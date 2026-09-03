<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\HttpClient;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
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

        // This decorator owns the W3C propagation headers for the span
        // it just created — a stale traceparent/tracestate the caller
        // (or a retried request that already carries one from a prior
        // attempt) supplied must be replaced, never left alongside the
        // new one: multiple traceparent values on one request are
        // invalid/ambiguous. Every other header entry — including one
        // this class cannot itself make sense of, like a numeric entry
        // with no colon or a non-string value — is carried through to
        // $inner completely untouched, exactly as the caller built it,
        // rather than silently dropped: only entries whose own name
        // resolves to a propagation field are ever touched at all.
        $options['headers'] = self::replacePropagationHeaders($headers, $carrier);

        // request() is allowed to throw synchronously (an invalid
        // option/URL, a decorator that validates eagerly) — caught here
        // specifically because TracingResponse's own deferred lifecycle
        // (its guarded()/finish() pair) never comes into existence when
        // that happens, so nothing else would ever end this span. The
        // exception's own message is the only thing recorded — never
        // $options/$headers, which may carry the propagation header or
        // whatever auth this call itself was made with.
        try {
            $response = $this->inner->request($method, $url, $options);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
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
     * Walks $headers in whatever shape Symfony's `headers` option
     * arrived in and drops only the entries whose own name resolves to
     * one of `TraceContextPropagator::FIELDS` (`traceparent`/
     * `tracestate`, case-insensitively), appending $carrier's freshly
     * injected values afterward under their own name — a carrier only
     * ever carries at most one of each field, so there's never a
     * collision to worry about there.
     *
     * Every other entry is handled one of two ways, decided *per
     * lowercase name*, not per entry — a first pass finds every name
     * with at least one entry `cleanValuesOf()` can't cleanly resolve
     * (an "opaque" occurrence), and no name in that set is ever
     * regrouped, even for its own otherwise-clean occurrences:
     * regrouping under a shared associative key risks exactly the
     * silent overwrite this exists to prevent, in either direction —
     * an opaque entry preserved first, then clobbered by a later clean
     * one regrouped under the identical key, or the reverse — regardless
     * of which order they appeared in $headers.
     *
     * - a name with **no** opaque occurrence has every one of its clean
     *   entries regrouped by lowercase name, so two separately repeated
     *   `"Name: value"` string entries for the same real header still
     *   reach `$inner` as one combined value; see `cleanValuesOf()`'s
     *   own docblock for why this regrouping step exists at all.
     * - a name with **any** opaque occurrence has *every* one of its
     *   entries — opaque or not — copied into the result under its own
     *   original key, with its own original value, completely
     *   unexamined and untouched: never partially filtered, never
     *   silently dropped, and never merged into a key another
     *   occurrence of the same name could also claim. A malformed entry
     *   that should have made the wrapped client throw, or that only
     *   that client's own implementation-specific extension
     *   understands, reaches it exactly as the caller built it — the
     *   real, disclosed cost being that this specific name's own clean
     *   occurrences, if any, no longer benefit from the regrouping that
     *   would otherwise protect them from Symfony's own downstream
     *   header-normalization reset behavior.
     *
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $carrier
     * @return array<array-key, mixed>
     */
    private static function replacePropagationHeaders(array $headers, array $carrier): array
    {
        /** @var array<string, true> $taintedNames */
        $taintedNames = [];

        foreach ($headers as $key => $value) {
            $name = self::headerNameOf($key, $value);

            if ($name === null) {
                continue;
            }

            $lower = strtolower($name);

            if (in_array($lower, TraceContextPropagator::FIELDS, true)) {
                continue;
            }

            if (self::cleanValuesOf($key, $value) === null) {
                $taintedNames[$lower] = true;
            }
        }

        $result = [];
        /** @var array<string, array{name: string, values: list<string>}> $named */
        $named = [];

        foreach ($headers as $key => $value) {
            $name = self::headerNameOf($key, $value);

            if ($name !== null && in_array(strtolower($name), TraceContextPropagator::FIELDS, true)) {
                continue;
            }

            if ($name === null || isset($taintedNames[strtolower($name)])) {
                $result[$key] = $value;

                continue;
            }

            /** @var list<string> $cleanValues */
            $cleanValues = self::cleanValuesOf($key, $value);
            $lower = strtolower($name);
            $named[$lower] ??= ['name' => $name, 'values' => []];
            array_push($named[$lower]['values'], ...$cleanValues);
        }

        foreach ($named as $entry) {
            $result[$entry['name']] = count($entry['values']) === 1 ? $entry['values'][0] : $entry['values'];
        }

        foreach ($carrier as $name => $value) {
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * The header name one raw $headers entry represents, or `null` when
     * none can be determined at all — an associative (string) key
     * always *is* the name, regardless of what shape its own value
     * takes, since Symfony's own header option treats the key as
     * authoritative there; a numeric key only has a name when its value
     * is itself a genuine `"Name: value"` string, since that's the only
     * shape a numeric entry is ever defined to carry one in.
     */
    private static function headerNameOf(int|string $key, mixed $value): ?string
    {
        if (is_string($key)) {
            return $key;
        }

        if (!is_string($value)) {
            return null;
        }

        $colon = strpos($value, ':');

        return $colon === false ? null : trim(substr($value, 0, $colon));
    }

    /**
     * The value(s) a *cleanly*-shaped entry (one `headerNameOf()`
     * already resolved a name for) carries, or `null` when the value
     * isn't cleanly one string or a list of only strings — the signal
     * `replacePropagationHeaders()` uses to fall back to preserving the
     * whole entry untouched rather than guessing at, or partially
     * filtering, a shape it can't safely re-derive. Regrouping only
     * ever happens for entries this method actually returns a real
     * value for, which is what keeps a malformed list member (or a
     * non-string value under an otherwise-valid-looking key) from being
     * silently dropped while its well-formed siblings get combined.
     *
     * Symfony's own header normalization resets its accumulator for a
     * given lowercase header name on *every separate* numeric-indexed
     * `"Name: value"` entry sharing it, rather than combining them — so
     * two genuinely repeated headers passed through this way would
     * otherwise collapse to just the last one by the time they reached
     * a Symfony-based transport. Regrouping clean entries into one
     * associative key's own list value here is what a downstream
     * client actually receives them intact as.
     *
     * @return list<string>|null
     */
    private static function cleanValuesOf(int|string $key, mixed $value): ?array
    {
        if (is_int($key)) {
            // headerNameOf() already confirmed $value is a colon-
            // containing string for this key to have a name at all.
            /** @var string $value */
            $colon = strpos($value, ':');

            return [trim(substr($value, $colon + 1))];
        }

        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $singleValue) {
            if (!is_string($singleValue)) {
                return null;
            }
        }

        return array_values($value);
    }
}
