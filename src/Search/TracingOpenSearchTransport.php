<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Search;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A client span per OpenSearch HTTP call, wrapping any PSR-18
 * `ClientInterface` — meant for the seam
 * `Kinetis\SearchOpenSearch\OpenSearchClientFactory::fromConfig()`
 * exposes via its `$transportDecorator` parameter:
 *
 *     OpenSearchClientFactory::fromConfig(
 *         $config,
 *         transportDecorator: static fn (ClientInterface $client): ClientInterface
 *             => new TracingOpenSearchTransport($client, $tracerProvider),
 *     );
 *
 * Composed after the factory's own Content-Type/auth/TLS options are
 * already applied, so this decorator never has to duplicate that
 * config-reading logic — it only ever sees a fully-configured client.
 *
 * PSR-18's `sendRequest()` is fully synchronous by contract (it always
 * hands back a complete response, never a lazy one), so unlike
 * `TracingHttpClient`/`TracingResponse` there is no deferred-consumption
 * span lifecycle to manage here — the span starts and ends around one
 * call.
 *
 * OpenSearch's REST API is path-based (`POST /{index}/_search`,
 * `GET /{index}/_doc/{id}`, ...), so the span name is derived from the
 * request's own method and its last `_`-prefixed "action" path segment
 * — legible without parsing the request body's query DSL.
 */
final readonly class TracingOpenSearchTransport implements ClientInterface
{
    private TracerInterface $tracer;

    public function __construct(
        private ClientInterface $inner,
        TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $span = $this->tracer->spanBuilder($this->operationName($request))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', 'opensearch')
            ->setAttribute('http.request.method', $request->getMethod())
            ->setAttribute('url.path', $request->getUri()->getPath())
            ->startSpan();

        try {
            $response = $this->inner->sendRequest($request);
            $span->setAttribute('http.response.status_code', $response->getStatusCode());

            if ($response->getStatusCode() >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @return non-empty-string
     */
    private function operationName(RequestInterface $request): string
    {
        $segments = array_values(array_filter(explode('/', $request->getUri()->getPath())));
        $action = null;

        foreach (array_reverse($segments) as $segment) {
            if (str_starts_with($segment, '_')) {
                $action = $segment;
                break;
            }
        }

        $action ??= $segments === [] ? 'request' : $segments[array_key_last($segments)];

        return strtoupper($request->getMethod()) . ' ' . $action;
    }
}
