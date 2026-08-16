<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Instrumentation;

use Kinetis\Instrumentation\TelemetryInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
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
 * Which hooks *activate* their span (making it the parent of whatever
 * starts next) is the load-bearing choice: only strictly-nested,
 * single-fiber pairs do — middleware, controller, event/listener, the
 * concurrently() batch, MCP tool calls, and worker jobs. Query and
 * per-task spans never activate: they can overlap across fibers on the
 * shared context, and activating them would interleave the scope stack.
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

    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        return $this->start('route.match', ['http.request.method' => $method, 'url.path' => $path]);
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
        $keyword = strtok(ltrim($sql), " \t\n\r(");

        return $this->start(
            $keyword === false ? 'SQL' : strtoupper($keyword),
            ['db.system.name' => $system, 'db.query.text' => $sql],
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

    #[\Override]
    public function taskStarted(int $index): mixed
    {
        return $this->start('task ' . $index, ['kinetis.task.index' => $index]);
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
        // it, the span roots a fresh trace exactly as before.
        $parent = $metadata === [] ? null : TraceContextPropagator::getInstance()->extract($metadata);

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

        return [$span, $activate ? $span->activate() : null];
    }

    private function end(mixed $token, ?Throwable $failure): void
    {
        if (!\is_array($token) || !($token[0] ?? null) instanceof SpanInterface) {
            return;
        }

        [$span, $scope] = $token;

        if ($failure !== null) {
            $span->recordException($failure);
            $span->setStatus(StatusCode::STATUS_ERROR, $failure->getMessage());
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
