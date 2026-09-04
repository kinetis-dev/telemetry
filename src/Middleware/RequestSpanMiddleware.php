<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Middleware;

use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * One server span per request, discovered as global middleware the
 * moment this package is installed. Priority 90 puts it outermost among
 * discovered middleware — the built-in global middlewares
 * (`GlobalMiddlewareOrder::resolve()`) and any explicit
 * `AppScope::middleware()` registration always run ahead of it
 * regardless of priority — so the span covers routing, the rest of the
 * pipeline, and the controller.
 *
 * The span is activated for its duration, which is what parents every
 * other span this package produces — a query, a queue push, an outgoing
 * HTTP call — under the request automatically, including inside
 * `concurrently()` tasks.
 *
 * An incoming `traceparent` header makes the span a child of the
 * caller's trace; the span name is the request's method, resolved
 * through {@see Redaction}'s own vocabulary, per OTel's HTTP semantic
 * conventions.
 *
 * No form of the request target travels on this span. A path is
 * caller-supplied and carries user ids, email addresses, document ids
 * and signed or single-use tokens as its segments, and the one shape of
 * it that would be safe — the matched route template — belongs to the
 * router, which runs inside `$handler` and publishes the match nowhere
 * this middleware can read it afterward. The framework's own
 * instrumentation hooks are where that template surfaces: with them
 * active, `route.match` is a child span of this one carrying the
 * template as `http.route`.
 */
#[AsGlobalMiddleware(priority: 90)]
final readonly class RequestSpanMiddleware implements MiddlewareInterface
{
    private TracerInterface $tracer;

    public function __construct(TracerProviderInterface $tracerProvider)
    {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parent = TraceContextPropagator::getInstance()->extract(array_change_key_case($request->getHeaders()));

        $method = $request->getMethod();
        $span = $this->tracer->spanBuilder(Redaction::httpSpanName($method))
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', Redaction::httpMethod($method))
            ->startSpan();
        $scope = $span->activate();

        try {
            $response = $handler->handle($request);

            $span->setAttribute('http.response.status_code', $response->getStatusCode());

            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (Throwable $e) {
            Redaction::recordFailure($span, $e);

            throw $e;
        } finally {
            // Per-request memory on the span, not a separate metrics
            // pipeline: under a persistent worker, a slow upward drift
            // of this attribute across a worker's own spans is the leak
            // detector.
            $span->setAttribute('php.memory.usage', memory_get_usage(true));
            $scope->detach();
            $span->end();
        }
    }
}
