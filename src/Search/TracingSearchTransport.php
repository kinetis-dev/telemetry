<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Search;

use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A client span per search HTTP call, wrapping any PSR-18
 * `ClientInterface` — meant for the seam every engine factory in this
 * project exposes via its `$transportDecorator` parameter:
 *
 *     ElasticsearchClientFactory::fromConfig(
 *         $config,
 *         transportDecorator: static fn (ClientInterface $client): ClientInterface
 *             => new TracingSearchTransport($client, $tracerProvider, SearchSystem::Elasticsearch),
 *     );
 *
 * OpenSearch and Elasticsearch answer the same path-based REST API, so
 * one decorator serves both; $system is what tells their spans apart.
 *
 * Composed after the factory's own origin, deadline, response bound,
 * Content-Type, auth and TLS options are already applied, so this
 * decorator never has to duplicate that config-reading logic — it only
 * ever sees a fully-configured client.
 *
 * That client reads the status, headers and body before it returns, so
 * unlike `TracingHttpClient`/`TracingResponse` there is no
 * deferred-consumption span lifecycle to manage here — the span starts
 * and ends around one call, and a body-phase failure falls inside it.
 *
 * A span is named from the request's method and the action its path
 * performs (`POST /{index}/_search`, `GET /{index}/_doc/{id}`, ...) —
 * legible without parsing the request body's query DSL. Both come from
 * {@see Redaction}'s closed vocabularies: the rest of such a path is
 * index names, aliases and document ids, which identify the records a
 * request touched rather than what it did, so the path travels only as
 * a fingerprint.
 */
final readonly class TracingSearchTransport implements ClientInterface
{
    private TracerInterface $tracer;

    public function __construct(
        private ClientInterface $inner,
        TracerProviderInterface $tracerProvider,
        private SearchSystem $system,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $action = Redaction::searchAction($path);

        $span = $this->tracer->spanBuilder(Redaction::httpSpanName($request->getMethod()) . ' ' . $action)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', $this->system->value)
            ->setAttribute('db.operation.name', $action)
            ->setAttribute('http.request.method', Redaction::httpMethod($request->getMethod()))
            ->setAttribute(
                'kinetis.search.path_fingerprint',
                Redaction::fingerprint(FingerprintDomain::SearchPath, $path),
            )
            ->startSpan();

        try {
            $response = $this->inner->sendRequest($request);
            $span->setAttribute('http.response.status_code', $response->getStatusCode());

            if ($response->getStatusCode() >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (Throwable $e) {
            Redaction::recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
        }
    }
}
