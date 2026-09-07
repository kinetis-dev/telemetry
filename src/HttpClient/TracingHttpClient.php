<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\HttpClient;

use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Throwable;

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
 *
 * The URL and the method reach `$inner` exactly as the caller wrote
 * them, and reach the span only through {@see Redaction} — scheme,
 * host and port from the URL, the method from a closed vocabulary. An
 * outgoing URL is caller-supplied and holds a credential often enough
 * that no other part of it can travel: `https://user:pass@host/`, an
 * API key or a signature as a query parameter, a token in the
 * fragment, a reset token or a document id as a path segment. A
 * general-purpose client has no route template to reduce a path to
 * either, so the whole URL's fingerprint is what correlates repeat
 * calls instead.
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
        $span = $this->tracer->spanBuilder(Redaction::httpSpanName($method))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.request.method', Redaction::httpMethod($method))
            ->setAttribute('kinetis.http.url_fingerprint', Redaction::fingerprint(FingerprintDomain::HttpUrl, $url))
            ->setAttributes(Redaction::urlAttributes($url))
            ->startSpan();

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, context: $span->storeInContext(Context::getCurrent()));

        $headers = $options['headers'] ?? [];

        // This decorator owns the W3C propagation headers for the span
        // it just created: a stale traceparent/tracestate — the
        // caller's, or one a retried request still carries from a prior
        // attempt — is dropped rather than left alongside the new one,
        // since multiple traceparent values on one request are
        // ambiguous. The contract's `headers` option is an iterable;
        // anything else is out of contract and reaches $inner for it to
        // reject.
        if (is_iterable($headers)) {
            $options['headers'] = self::replacePropagationHeaders($headers, $carrier);
        }

        // request() is allowed to throw synchronously (an invalid
        // option/URL, a decorator that validates eagerly) — caught here
        // specifically because TracingResponse's own deferred lifecycle
        // (its guarded()/finish() pair) never comes into existence when
        // that happens, so nothing else would ever end this span. A
        // client's own exception message quotes the URL it was handed,
        // credentials included, which is why only the exception's class
        // is recorded; $options/$headers never reach the span at all.
        try {
            $response = $this->inner->request($method, $url, $options);
        } catch (Throwable $e) {
            Redaction::recordFailure($span, $e);
            $span->end();

            throw $e;
        }

        return new TracingResponse($response, $span);
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

    /**
     * One pass over $headers, dropping every entry whose own name is
     * one of `TraceContextPropagator::FIELDS` and appending $carrier's
     * freshly injected values afterward — a carrier only ever carries
     * at most one of each field, so nothing there can collide.
     *
     * A name comes from an associative key, or from the prefix before
     * the first colon of a numeric entry's string value, matched
     * case-insensitively. An entry with no name this decorator can
     * recognize is unrelated to propagation and reaches $inner as
     * given, for the wrapped client to accept or reject. Every retained
     * entry keeps its own value and its position in the sequence:
     * headers this decorator does not own are not validated, regrouped,
     * coerced or repaired here.
     *
     * @param iterable<mixed, mixed> $headers
     * @param array<array-key, mixed> $carrier
     * @return array<array-key, mixed>
     */
    private static function replacePropagationHeaders(iterable $headers, array $carrier): array
    {
        $result = [];

        foreach ($headers as $key => $value) {
            $name = is_string($key)
                ? $key
                : (is_string($value) ? strstr($value, ':', true) : false);

            if (is_string($name) && in_array(strtolower(trim($name)), TraceContextPropagator::FIELDS, true)) {
                continue;
            }

            if (is_string($key)) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        foreach ($carrier as $name => $value) {
            $result[$name] = $value;
        }

        return $result;
    }
}
