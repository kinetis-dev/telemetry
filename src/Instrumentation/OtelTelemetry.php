<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Instrumentation;

use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * Turns the framework's instrumentation hooks into OTel spans — what
 * PackageBootstrap swaps into `Telemetry::global()` when an OTLP
 * endpoint is configured.
 *
 * Scope ownership is Fiber-local: OTel's default context storage binds
 * a scope stack to the Fiber that attached it, so a scope attached in
 * one cooperative task is invisible to — and cannot be detached out of
 * order by — a sibling task suspended over the same stretch of time.
 *
 * Which hooks *activate* their span (making it the parent of whatever
 * starts next) is the load-bearing choice. The nested, same-Fiber pairs
 * activate on whatever context their Fiber already carries: middleware,
 * controller, event/listener, the concurrently() batch, and MCP tool
 * calls. `taskStarted()` and `jobStarted()` activate on a parent context
 * they build themselves — the batch span for a task, the propagated or
 * root context for a job — because each begins on a Fiber whose context
 * may be uninitialized, where reading the ambient context warns instead
 * of answering. Query spans never activate at all: they overlap within a
 * single Fiber, and activating them would interleave that Fiber's own
 * stack.
 *
 * The hooks hand over the same operation inputs the decorators see, and
 * they reach a span under the same rules — {@see Redaction} states
 * them: the SQL a driver reports and the path a request arrived on
 * never travel, and neither does the message or stack trace of whatever
 * failure ends a hook pair. The MCP tool name and resource URI are the
 * exception that needs no handling: `McpDispatcher` reports them only
 * after resolving them against the registry, so both are members of a
 * closed registered set rather than caller-supplied text.
 */
final readonly class OtelTelemetry implements TelemetryInterface
{
    private TracerInterface $tracer;

    public function __construct(TracerProviderInterface $tracerProvider)
    {
        $this->tracer = $tracerProvider->getTracer('kinetis-hooks');
    }

    #[\Override]
    public function phase(string $name, float $startedAt, float $endedAt): void
    {
        $this->tracer->spanBuilder($name === '' ? 'phase' : $name)
            ->setStartTimestamp((int) ($startedAt * 1_000_000_000))
            ->startSpan()
            ->end((int) ($endedAt * 1_000_000_000));
    }

    /**
     * The path this hook reports is the raw request target, so it stops
     * here: what describes the request without naming the record it
     * addressed is the template the router resolves it to, which
     * arrives moments later through {@see routeMatchEnded()} as
     * `http.route`. A request that matches no route carries the method
     * alone, which is the whole of what is known about it.
     */
    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        return $this->start('route.match', ['http.request.method' => Redaction::httpMethod($method)]);
    }

    #[\Override]
    public function routeMatchEnded(mixed $token, ?string $pattern): void
    {
        if ($pattern !== null) {
            $this->spanOf($token)?->setAttribute('http.route', $pattern);
        }

        $this->end($token, null);
    }

    #[\Override]
    public function middlewareEntered(string $class): mixed
    {
        return $this->start('middleware ' . self::shortName($class), ['kinetis.class' => $class], activate: true);
    }

    #[\Override]
    public function middlewareExited(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    #[\Override]
    public function hydrationStarted(string $dtoClass): mixed
    {
        return $this->start('hydrate ' . self::shortName($dtoClass), ['kinetis.class' => $dtoClass]);
    }

    #[\Override]
    public function hydrationEnded(mixed $token): void
    {
        $this->end($token, null);
    }

    #[\Override]
    public function controllerInvoked(string $class, string $method): mixed
    {
        return $this->start(self::shortName($class) . '::' . $method, ['kinetis.class' => $class], activate: true);
    }

    #[\Override]
    public function controllerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    #[\Override]
    public function responseEncodingStarted(): mixed
    {
        return $this->start('response.encode');
    }

    #[\Override]
    public function responseEncodingEnded(mixed $token): void
    {
        $this->end($token, null);
    }

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        $operation = Redaction::sqlOperation($sql);

        return $this->start(
            $operation,
            [
                'db.system.name' => $system,
                'db.operation.name' => $operation,
                'kinetis.db.query_fingerprint' => Redaction::fingerprint(FingerprintDomain::SqlStatement, $sql),
            ],
            kind: SpanKind::KIND_CLIENT,
        );
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        // Everything before this event is time spent waiting for a free
        // pooled connection.
        $this->spanOf($token)?->addEvent('server.started');
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        return $this->start('transaction', ['db.system.name' => $system], kind: SpanKind::KIND_CLIENT);
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->spanOf($token)?->setAttribute('db.transaction.outcome', $outcome);
        $this->end($token, null);
    }

    #[\Override]
    public function taskBatchStarted(int $count): mixed
    {
        return $this->start('concurrently', ['kinetis.task.count' => $count], activate: true);
    }

    #[\Override]
    public function taskBatchEnded(mixed $token): void
    {
        $this->end($token, null);
    }

    /**
     * A task begins on its own Fiber, which carries no context of its
     * own, so the batch span reached through $batchToken is the explicit
     * parent — and the base its scope attaches on. A token that carries
     * no span (a backend failure contained by the facade, which hands
     * every task `null`) makes the task a root instead of an orphan.
     */
    #[\Override]
    public function taskStarted(int $index, mixed $batchToken): mixed
    {
        $root = Context::getRoot();
        $batch = $this->spanOf($batchToken);

        return $this->start(
            'task ' . $index,
            ['kinetis.task.index' => $index],
            activate: true,
            parent: $batch?->storeInContext($root) ?? $root,
        );
    }

    #[\Override]
    public function taskEnded(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    #[\Override]
    public function eventDispatched(string $eventClass): mixed
    {
        return $this->start('event ' . self::shortName($eventClass), ['kinetis.class' => $eventClass], activate: true);
    }

    #[\Override]
    public function eventSettled(mixed $token): void
    {
        $this->end($token, null);
    }

    #[\Override]
    public function listenerInvoked(string $listenerClass, string $method): mixed
    {
        return $this->start(
            'listener ' . self::shortName($listenerClass),
            ['kinetis.class' => $listenerClass, 'kinetis.method' => $method],
            activate: true,
        );
    }

    #[\Override]
    public function listenerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    #[\Override]
    public function toolCallStarted(string $tool): mixed
    {
        return $this->start('tool ' . $tool, ['kinetis.mcp.tool' => $tool], activate: true);
    }

    #[\Override]
    public function toolCallEnded(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    #[\Override]
    public function resourceReadStarted(string $uri): mixed
    {
        return $this->start('resource ' . $uri, ['kinetis.mcp.resource' => $uri]);
    }

    #[\Override]
    public function resourceReadEnded(mixed $token): void
    {
        $this->end($token, null);
    }

    #[\Override]
    public function jobPushStarted(string $jobClass, string $queue): mixed
    {
        return $this->start(
            "{$queue} publish",
            ['messaging.destination.name' => $queue, 'kinetis.job.class' => $jobClass],
            kind: SpanKind::KIND_PRODUCER,
        );
    }

    /**
     * The propagation channel: a traceparent carrier for the backend to
     * store with the job, so the consumer span joins this trace from
     * another process.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function jobPushMetadata(mixed $token): array
    {
        $span = $this->spanOf($token);

        if ($span === null) {
            return [];
        }

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, context: $span->storeInContext(Context::getCurrent()));

        return $carrier;
    }

    #[\Override]
    public function jobPushEnded(mixed $token, ?Throwable $failure): void
    {
        $this->end($token, $failure);
    }

    /**
     * @param array<string, string> $metadata
     */
    #[\Override]
    public function jobStarted(string $jobClass, string $queue, int $attempt, array $metadata = []): mixed
    {
        // Metadata carried from push() parents this consumer span into
        // the producer's own trace — one trace across processes. Without
        // it the job roots a trace of its own. Either way the parent is
        // named, which is also the base this span's scope attaches on:
        // a worker's job may begin on a Fiber that carries no context.
        $root = Context::getRoot();
        $parent = $metadata === []
            ? $root
            : TraceContextPropagator::getInstance()->extract($metadata, context: $root);

        return $this->start(
            "{$queue} process",
            [
                'messaging.destination.name' => $queue,
                'kinetis.job.class' => $jobClass,
                'kinetis.job.attempt' => $attempt,
            ],
            kind: SpanKind::KIND_CONSUMER,
            activate: true,
            parent: $parent,
        );
    }

    #[\Override]
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void
    {
        $this->spanOf($token)?->setAttribute('kinetis.job.outcome', $outcome);
        $this->end($token, $failure);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param SpanKind::KIND_* $kind
     * @return array{SpanInterface, ?ScopeInterface}
     */
    private function start(
        string $name,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
        bool $activate = false,
        ?ContextInterface $parent = null,
    ): array {
        $span = $this->tracer->spanBuilder($name === '' ? 'kinetis' : $name)
            ->setParent($parent)
            ->setSpanKind($kind)
            ->setAttributes($attributes)
            ->startSpan();

        if (!$activate) {
            return [$span, null];
        }

        // An explicit parent is also the context the scope attaches on:
        // `SpanInterface::activate()` would read the current context
        // first, which is what a hook running on a Fiber of its own
        // must not do. Without one, the hook is a nested pair on an
        // initialized Fiber and inherits what that Fiber carries.
        return [
            $span,
            $parent === null ? $span->activate() : $span->storeInContext($parent)->activate(),
        ];
    }

    private function end(mixed $token, ?Throwable $failure): void
    {
        if (!\is_array($token) || !($token[0] ?? null) instanceof SpanInterface) {
            return;
        }

        [$span, $scope] = $token;

        if ($failure !== null) {
            Redaction::recordFailure($span, $failure);
        }

        if ($scope instanceof ScopeInterface) {
            $scope->detach();
        }

        $span->end();
    }

    private function spanOf(mixed $token): ?SpanInterface
    {
        return \is_array($token) && ($token[0] ?? null) instanceof SpanInterface ? $token[0] : null;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
