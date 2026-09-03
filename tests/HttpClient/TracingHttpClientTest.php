<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\HttpClient;

use Kinetis\Telemetry\HttpClient\TracingHttpClient;
use Kinetis\Telemetry\Tests\Fixtures\RecordingHttpClient;
use Kinetis\Telemetry\Tests\Fixtures\ThrowingHttpClient;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class TracingHttpClientTest extends TracingTestCase
{
    public function test_a_consumed_request_produces_a_client_span_with_the_status(): void
    {
        $client = new TracingHttpClient(new MockHttpClient([new MockResponse('{"ok":true}')]), $this->tracerProvider);

        $response = $client->request('GET', 'https://api.test/orders');
        self::assertSame([], $this->spans(), 'The span must stay open until the response is consumed.');

        $response->getContent();

        $span = $this->span();
        self::assertSame('GET', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('https://api.test/orders', $span->getAttributes()->get('url.full'));
        self::assertSame(200, $span->getAttributes()->get('http.response.status_code'));
    }

    public function test_a_traceparent_header_is_injected_carrying_the_client_spans_own_id(): void
    {
        $seenHeaders = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenHeaders): MockResponse {
            $seenHeaders = $options['headers'] ?? [];

            return new MockResponse('{}');
        });

        new TracingHttpClient($transport, $this->tracerProvider)->request('GET', 'https://api.test/')->getContent();

        $traceparent = null;

        foreach ($seenHeaders as $header) {
            if (is_string($header) && str_starts_with(strtolower($header), 'traceparent:')) {
                $traceparent = trim(substr($header, strlen('traceparent:')));
            }
        }

        self::assertNotNull($traceparent, 'Expected a traceparent header on the outgoing request.');
        $span = $this->span();
        self::assertSame("00-{$span->getTraceId()}-{$span->getSpanId()}-01", $traceparent);
    }

    public function test_a_stale_traceparent_in_associative_form_is_replaced_not_duplicated(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders([
            'traceparent' => '00-stale00000000000000000000001-stale000000001-01',
            'X-Foo' => 'bar',
        ]);

        $span = $this->span();
        self::assertSame(
            ["00-{$span->getTraceId()}-{$span->getSpanId()}-01"],
            self::headerValues($seenHeaders, 'traceparent'),
        );
        self::assertSame(['bar'], self::headerValues($seenHeaders, 'X-Foo'));
    }

    public function test_a_stale_traceparent_in_string_list_form_is_replaced_not_duplicated(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders([
            'traceparent: 00-stale00000000000000000000001-stale000000001-01',
            'X-Foo: bar',
        ]);

        $span = $this->span();
        self::assertSame(
            ["00-{$span->getTraceId()}-{$span->getSpanId()}-01"],
            self::headerValues($seenHeaders, 'traceparent'),
        );
        self::assertSame(['bar'], self::headerValues($seenHeaders, 'X-Foo'));
    }

    public function test_a_stale_traceparent_and_tracestate_with_mixed_casing_are_replaced(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders([
            'TraceParent' => '00-stale00000000000000000000001-stale000000001-01',
            'TRACESTATE' => 'vendor=stale',
        ]);

        $span = $this->span();
        self::assertSame(
            ["00-{$span->getTraceId()}-{$span->getSpanId()}-01"],
            self::headerValues($seenHeaders, 'traceparent'),
        );
        // No stale tracestate survives, and no new one replaces it: a
        // freshly started span's context carries no tracestate of its
        // own, so the propagator emits none.
        self::assertSame([], self::headerValues($seenHeaders, 'tracestate'));
    }

    public function test_multiple_stale_traceparent_entries_are_all_removed(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders([
            'traceparent: 00-stale00000000000000000000001-stale000000001-01',
            'traceparent: 00-stale00000000000000000000002-stale000000002-01',
            'X-Foo: bar',
        ]);

        self::assertCount(1, self::headerValues($seenHeaders, 'traceparent'));
        self::assertSame(['bar'], self::headerValues($seenHeaders, 'X-Foo'));
    }

    public function test_a_stale_tracestate_with_no_replacement_traceparent_conflict_is_removed(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders(['tracestate' => 'vendor=stale']);

        self::assertSame([], self::headerValues($seenHeaders, 'tracestate'));
        self::assertCount(1, self::headerValues($seenHeaders, 'traceparent'));
    }

    public function test_unrelated_repeated_headers_survive_in_associative_list_form(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders(['X-Foo' => ['bar', 'baz']]);

        self::assertSame(['bar', 'baz'], self::headerValues($seenHeaders, 'X-Foo'));
    }

    public function test_unrelated_repeated_headers_survive_in_string_list_form(): void
    {
        $seenHeaders = $this->requestAndCaptureHeaders(['X-Foo: bar', 'X-Foo: baz']);

        self::assertSame(['bar', 'baz'], self::headerValues($seenHeaders, 'X-Foo'));
    }

    /**
     * `MockHttpClient` runs every request through Symfony's own
     * `HttpClientTrait::normalizeHeaders()` before a callback ever sees
     * it — and that method itself resets its accumulator for a
     * lowercase header name on every top-level array entry sharing it,
     * which happens to collapse a straightforward
     * stale-entry-then-appended-new-entry pair down to just the last one
     * regardless of what this decorator does. So the tests above, which
     * capture what `MockHttpClient`'s callback receives, can't fully
     * distinguish this decorator's own correctness from that downstream
     * behavior for every case. This test inspects the raw `$options`
     * this decorator itself hands to `$inner->request()`, via a fixture
     * with no normalization of its own, proving the fix directly rather
     * than relying on a downstream client's own behavior to mask a
     * defect that could still exist here.
     */
    public function test_the_raw_headers_option_carries_no_stale_propagation_entry(): void
    {
        $inner = new RecordingHttpClient();

        // A bare MockResponse (not one issued through MockHttpClient's
        // own pipeline) can't have its body read, so the span is ended
        // via the discard-and-collect path instead — exactly the same
        // mechanism test_a_discarded_response_still_ends_its_span
        // already relies on.
        $response = new TracingHttpClient($inner, $this->tracerProvider)->request('GET', 'https://api.test/', [
            'headers' => [
                'traceparent' => '00-stale00000000000000000000001-stale000000001-01',
                'TraceState' => 'vendor=stale',
                'X-Foo' => 'bar',
            ],
        ]);
        unset($response);
        gc_collect_cycles();

        /** @var array<string, mixed> $headers */
        $headers = $inner->lastOptions['headers'];
        $lowercaseKeys = array_map(strtolower(...), array_keys($headers));

        self::assertSame(['x-foo', 'traceparent'], $lowercaseKeys);

        $span = $this->span();
        self::assertSame("00-{$span->getTraceId()}-{$span->getSpanId()}-01", $headers['traceparent']);
        self::assertSame('bar', $headers['X-Foo']);
    }

    /**
     * A numeric entry that isn't a parseable `"Name: value"` string —
     * this decorator has no name to check it against at all, so it must
     * reach $inner exactly as given rather than being silently dropped:
     * the wrapped client is the one that gets to decide whether it's
     * meaningful or an error.
     */
    public function test_a_colonless_numeric_header_entry_is_preserved_exactly(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders(['not-a-header', 'X-Foo: bar']);

        self::assertTrue(in_array('not-a-header', $headers, true));
    }

    /**
     * A numeric entry whose value isn't even a string — nothing to
     * parse a name out of, so, like the colonless case above, it must
     * survive untouched rather than being skipped.
     */
    public function test_a_numeric_header_entry_with_a_non_string_value_is_preserved_exactly(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([0 => 123, 'X-Foo' => 'bar']);

        self::assertTrue(in_array(123, $headers, true));
    }

    /**
     * An associative entry whose value isn't a string (or a list of
     * strings) at all — the *name* here ('X-Foo') is determinable, but
     * this decorator must not guess at, coerce, or drop the malformed
     * value it's paired with.
     */
    public function test_an_associative_header_entry_with_a_non_string_value_is_preserved_exactly(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders(['X-Foo' => 123]);

        self::assertSame(123, $headers['X-Foo']);
    }

    /**
     * A list value with one malformed member alongside otherwise-valid
     * ones — the whole entry is preserved exactly as given rather than
     * silently filtering the malformed member out and keeping only the
     * valid ones, which would still be data loss even though it looks
     * partially successful.
     */
    public function test_an_associative_header_entry_with_a_mixed_valid_and_invalid_list_value_is_preserved_exactly(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders(['X-Foo' => ['bar', 123]]);

        self::assertSame(['bar', 123], $headers['X-Foo']);
    }

    /**
     * The two behaviors compose in one request: an opaque/malformed
     * entry with nothing to do with propagation survives untouched, a
     * genuinely propagation-named entry is still replaced, and neither
     * one interferes with the other.
     */
    public function test_opaque_malformed_entries_are_preserved_alongside_stripped_propagation_headers(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([
            'traceparent' => '00-stale00000000000000000000001-stale000000001-01',
            'not-a-header',
            'X-Weird' => 123,
        ]);

        self::assertTrue(in_array('not-a-header', $headers, true));
        self::assertSame(123, $headers['X-Weird']);

        $span = $this->span();
        self::assertSame("00-{$span->getTraceId()}-{$span->getSpanId()}-01", $headers['traceparent']);
    }

    /**
     * An opaque associative entry and a clean numeric "Name: value"
     * entry sharing the identical name must both survive — regrouping
     * the clean one into the same key the opaque one already occupies
     * would silently overwrite it, and preserving the opaque one first
     * would silently drop the clean one instead if regrouping ran
     * after. Neither direction is acceptable, so neither is regrouped.
     */
    public function test_an_opaque_associative_entry_and_a_clean_numeric_entry_sharing_a_name_both_survive_opaque_first(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([
            'X-Foo' => 123,
            'X-Foo: bar',
        ]);

        self::assertSame(123, $headers['X-Foo']);
        self::assertTrue(in_array('X-Foo: bar', $headers, true));
    }

    public function test_an_opaque_associative_entry_and_a_clean_numeric_entry_sharing_a_name_both_survive_clean_first(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([
            'X-Foo: bar',
            'X-Foo' => 123,
        ]);

        self::assertSame(123, $headers['X-Foo']);
        self::assertTrue(in_array('X-Foo: bar', $headers, true));
    }

    /**
     * The identical collision, but the two occurrences use different
     * casing — still recognized as the same name (HTTP header names are
     * case-insensitive), so both still survive rather than one clobbering
     * the other under a case-normalized shared key.
     */
    public function test_an_opaque_and_a_clean_entry_sharing_a_case_variant_name_both_survive_opaque_first(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([
            'X-Foo' => 123,
            'x-foo: bar',
        ]);

        self::assertSame(123, $headers['X-Foo']);
        self::assertTrue(in_array('x-foo: bar', $headers, true));
    }

    public function test_an_opaque_and_a_clean_entry_sharing_a_case_variant_name_both_survive_clean_first(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([
            'x-foo: bar',
            'X-Foo' => 123,
        ]);

        self::assertSame(123, $headers['X-Foo']);
        self::assertTrue(in_array('x-foo: bar', $headers, true));
    }

    /**
     * The collision-preservation behavior above and propagation
     * replacement still compose correctly in one request: the colliding
     * pair both survive, and the stale traceparent is still replaced
     * with the real span's own value, not left in place.
     */
    public function test_a_same_name_collision_and_propagation_replacement_compose_in_one_request(): void
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $this->requestAndCaptureRawHeaders([
            'traceparent' => '00-stale00000000000000000000001-stale000000001-01',
            'X-Foo' => 123,
            'X-Foo: bar',
        ]);

        self::assertSame(123, $headers['X-Foo']);
        self::assertTrue(in_array('X-Foo: bar', $headers, true));

        $span = $this->span();
        self::assertSame("00-{$span->getTraceId()}-{$span->getSpanId()}-01", $headers['traceparent']);
    }

    /**
     * Sends $headers through a real TracingHttpClient wrapping
     * RecordingHttpClient (no normalization of its own — see that
     * fixture's own docblock) and returns exactly what this decorator
     * itself produced for $options['headers'].
     *
     * @param array<array-key, mixed> $headers
     * @return array<array-key, mixed>
     */
    private function requestAndCaptureRawHeaders(array $headers): array
    {
        $inner = new RecordingHttpClient();

        $response = new TracingHttpClient($inner, $this->tracerProvider)->request('GET', 'https://api.test/', [
            'headers' => $headers,
        ]);
        unset($response);
        gc_collect_cycles();

        return $inner->lastOptions['headers'];
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<array-key, mixed>
     */
    private function requestAndCaptureHeaders(array $headers): array
    {
        $seenHeaders = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenHeaders): MockResponse {
            $seenHeaders = $options['headers'] ?? [];

            return new MockResponse('{}');
        });

        new TracingHttpClient($transport, $this->tracerProvider)
            ->request('GET', 'https://api.test/', ['headers' => $headers])
            ->getContent();

        return $seenHeaders;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return list<string>
     */
    private static function headerValues(array $headers, string $name): array
    {
        $values = [];

        foreach ($headers as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }

            [$headerName, $headerValue] = explode(':', $header, 2);

            if (strtolower(trim($headerName)) === strtolower($name)) {
                $values[] = trim($headerValue);
            }
        }

        return $values;
    }

    public function test_an_error_status_marks_the_span_without_throwing(): void
    {
        $client = new TracingHttpClient(new MockHttpClient([new MockResponse('nope', ['http_code' => 503])]), $this->tracerProvider);

        $client->request('GET', 'https://api.test/')->getContent(false);

        $span = $this->span();
        self::assertSame(503, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    public function test_a_transport_failure_records_the_exception_and_ends_the_span(): void
    {
        $client = new TracingHttpClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused'])),
            $this->tracerProvider,
        );

        $response = $client->request('GET', 'https://unreachable.test/');

        try {
            $response->getContent();
            self::fail('Expected the transport failure to propagate.');
        } catch (TransportExceptionInterface) {
        }

        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNotSame([], $span->getEvents());
    }

    /**
     * Distinct from the transport-failure test above: `request()` here
     * throws immediately, synchronously — `MockHttpClient` can only ever
     * defer an error to response consumption, so this needs a hand-
     * written `HttpClientInterface` fixture to reach the path at all.
     * With no `TracingResponse` ever constructed, nothing but this
     * method's own catch clause can end the span.
     */
    public function test_a_synchronous_request_failure_records_the_exception_and_ends_the_span(): void
    {
        $client = new TracingHttpClient(new ThrowingHttpClient(), $this->tracerProvider);

        try {
            $client->request('GET', 'https://api.test/');
            self::fail('Expected the synchronous transport failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('synchronous transport failure', $e->getMessage());
        }

        self::assertCount(1, $this->spans());
        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNotSame([], $span->getEvents());
    }

    public function test_a_discarded_response_still_ends_its_span(): void
    {
        $client = new TracingHttpClient(new MockHttpClient([new MockResponse('{}')]), $this->tracerProvider);

        $response = $client->request('GET', 'https://api.test/');
        unset($response);
        gc_collect_cycles();

        self::assertCount(1, $this->spans());
    }

    public function test_get_status_code_records_the_status_but_keeps_the_span_open(): void
    {
        $client = new TracingHttpClient(new MockHttpClient([new MockResponse('{}')]), $this->tracerProvider);

        $response = $client->request('GET', 'https://api.test/');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->spans());

        $response->getContent();
        self::assertCount(1, $this->spans());
    }

    public function test_with_options_returns_a_still_tracing_client(): void
    {
        $client = new TracingHttpClient(new MockHttpClient([new MockResponse('{}')]), $this->tracerProvider);

        $client->withOptions(['timeout' => 5])->request('GET', 'https://api.test/')->getContent();

        self::assertCount(1, $this->spans());
    }
}
