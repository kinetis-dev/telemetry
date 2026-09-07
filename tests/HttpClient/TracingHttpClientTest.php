<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\HttpClient;

use Generator;
use Kinetis\Telemetry\HttpClient\TracingHttpClient;
use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
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
        self::assertSame('https', $span->getAttributes()->get('url.scheme'));
        self::assertSame('api.test', $span->getAttributes()->get('server.address'));
        self::assertNull($span->getAttributes()->get('url.path'));
        self::assertSame(
            Redaction::fingerprint(FingerprintDomain::HttpUrl, 'https://api.test/orders'),
            $span->getAttributes()->get('kinetis.http.url_fingerprint'),
        );
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

    /**
     * Two repeated numeric entries for one unrelated header reach
     * $inner in the order and the shape the caller wrote them: a header
     * this decorator does not own is neither regrouped nor rewritten by
     * it, and the wrapped client sees the sequence it was given.
     */
    public function test_repeated_unrelated_numeric_entries_reach_the_inner_client_in_order(): void
    {
        $headers = $this->requestAndCaptureRawHeaders(['X-Foo: a', 'X-Foo: b']);

        $span = $this->span();
        self::assertSame([
            'X-Foo: a',
            'X-Foo: b',
            'traceparent' => "00-{$span->getTraceId()}-{$span->getSpanId()}-01",
        ], $headers);
    }

    /**
     * The `headers` option is an iterable under the contract, not only
     * an array. A generator is walked exactly once — the only way it
     * can be walked at all — and what it yielded reaches $inner with
     * the stale propagation entry gone and the fresh one appended.
     */
    public function test_a_generator_headers_option_is_consumed_once_and_reaches_the_inner_client(): void
    {
        $yielded = 0;
        $headers = (static function () use (&$yielded): Generator {
            $yielded++;

            yield 'traceparent' => '00-stale00000000000000000000001-stale000000001-01';

            $yielded++;

            yield 'X-Foo: bar';
        })();

        $inner = new RecordingHttpClient();

        $response = new TracingHttpClient($inner, $this->tracerProvider)
            ->request('GET', 'https://api.test/', ['headers' => $headers]);
        unset($response);
        gc_collect_cycles();

        self::assertSame(2, $yielded);
        self::assertFalse($headers->valid());

        $span = $this->span();
        self::assertSame([
            'X-Foo: bar',
            'traceparent' => "00-{$span->getTraceId()}-{$span->getSpanId()}-01",
        ], $inner->lastOptions['headers']);
    }

    /**
     * The tests above assert on what `MockHttpClient` passes to its
     * callback, which is what that client's own normalization made of
     * this decorator's output. This one reads the raw `$options` handed
     * to `$inner->request()` instead, through a fixture that normalizes
     * nothing, so it holds this decorator to its own result.
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
     * Sends $headers through a real TracingHttpClient wrapping
     * RecordingHttpClient and returns exactly what this decorator
     * produced for $options['headers'].
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
