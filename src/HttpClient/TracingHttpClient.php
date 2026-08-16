<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\HttpClient;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A client span per outgoing request, plus `traceparent` injection so
 * the downstream service joins the trace. Wraps any Symfony
 * `HttpClientInterface` — hand it to `Http` as its transport:
 *
 *     $app->instance(Http::class, new Http(new TracingHttpClient(
 *         AmpHttpClientFactory::create(),
 *         $app->get(TracerProviderInterface::class),
 *     )));
 *
 * Requests here return immediately and complete later, so the span
 * ends when the response is actually consumed (or errors), not when
 * `request()` returns — otherwise every span would report near-zero
 * duration. When composing with `Http::withRetries()`, wrap this
 * decorator first and add retries on top: each attempt then gets its
 * own span, so the failure that triggered a retry stays visible.
 */
final readonly class TracingHttpClient implements HttpClientInterface
{
    private TracerInterface $tracer;

    public function __construct(
        private HttpClientInterface $inner,
        private TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $span = $this->tracer->spanBuilder($method === '' ? 'HTTP' : $method)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.request.method', $method)
            ->setAttribute('url.full', $url)
            ->startSpan();

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, context: $span->storeInContext(Context::getCurrent()));

        /** @var array<array-key, mixed> $headers */
        $headers = $options['headers'] ?? [];

        foreach ($carrier as $name => $value) {
            // Appended in Symfony's "Name: value" string form, which
            // coexists with whatever shape the existing headers use.
            $headers[] = "{$name}: {$value}";
        }

        $options['headers'] = $headers;

        return new TracingResponse($this->inner->request($method, $url, $options), $span);
    }

    #[\Override]
    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        // Symfony clients only stream responses they created themselves,
        // so the wrappers are unwrapped here. Their spans then end on
        // destruct rather than on a read through the wrapper — stream
        // consumers get coarser span timing, disclosed in TracingResponse.
        $unwrapped = [];

        foreach ($responses as $response) {
            $unwrapped[] = $response instanceof TracingResponse ? $response->unwrap() : $response;
        }

        return $this->inner->stream($unwrapped, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->tracerProvider);
    }
}
