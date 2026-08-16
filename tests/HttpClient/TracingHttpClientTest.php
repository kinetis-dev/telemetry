<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\HttpClient;

use Kinetis\Telemetry\HttpClient\TracingHttpClient;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
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
