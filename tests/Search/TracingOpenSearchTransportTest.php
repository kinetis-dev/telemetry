<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Search;

use Kinetis\Telemetry\Search\TracingOpenSearchTransport;
use Kinetis\Telemetry\Tests\Fixtures\FakePsr18Client;
use Kinetis\Telemetry\Tests\TracingTestCase;
use Nyholm\Psr7\Request;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

final class TracingOpenSearchTransportTest extends TracingTestCase
{
    public function test_a_search_request_produces_a_client_span_naming_the_action(): void
    {
        $inner = new FakePsr18Client();
        $transport = new TracingOpenSearchTransport($inner, $this->tracerProvider);

        $transport->sendRequest(new Request('POST', 'http://localhost:9200/orders/_search'));

        $span = $this->span();
        self::assertSame('POST _search', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('opensearch', $span->getAttributes()->get('db.system.name'));
        self::assertSame('/orders/_search', $span->getAttributes()->get('url.path'));
        self::assertSame(200, $span->getAttributes()->get('http.response.status_code'));
    }

    public function test_a_document_get_names_the_doc_action_not_the_id(): void
    {
        $inner = new FakePsr18Client();
        $transport = new TracingOpenSearchTransport($inner, $this->tracerProvider);

        $transport->sendRequest(new Request('GET', 'http://localhost:9200/orders/_doc/42'));

        self::assertSame('GET _doc', $this->span()->getName());
    }

    public function test_a_request_with_no_action_segment_falls_back_to_the_last_path_segment(): void
    {
        $inner = new FakePsr18Client();
        $transport = new TracingOpenSearchTransport($inner, $this->tracerProvider);

        $transport->sendRequest(new Request('PUT', 'http://localhost:9200/orders'));

        self::assertSame('PUT orders', $this->span()->getName());
    }

    public function test_an_error_status_marks_the_span_as_an_error_without_throwing(): void
    {
        $inner = new FakePsr18Client(status: 404);
        $transport = new TracingOpenSearchTransport($inner, $this->tracerProvider);

        $response = $transport->sendRequest(new Request('GET', 'http://localhost:9200/orders/_doc/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_a_transport_failure_marks_the_span_as_an_error_and_rethrows(): void
    {
        $inner = new FakePsr18Client(failWith: 'connection refused');
        $transport = new TracingOpenSearchTransport($inner, $this->tracerProvider);

        try {
            $transport->sendRequest(new Request('POST', 'http://localhost:9200/orders/_search'));
            self::fail('Expected the transport exception to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_the_wrapped_client_actually_receives_the_request(): void
    {
        $inner = new FakePsr18Client();
        $transport = new TracingOpenSearchTransport($inner, $this->tracerProvider);

        $request = new Request('POST', 'http://localhost:9200/orders/_search');
        $transport->sendRequest($request);

        self::assertSame($request, $inner->lastRequest);
    }
}
