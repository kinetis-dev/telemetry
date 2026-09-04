<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Middleware;

use Kinetis\Telemetry\Middleware\RequestSpanMiddleware;
use Kinetis\Telemetry\Tests\TracingTestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class RequestSpanMiddlewareTest extends TracingTestCase
{
    public function test_a_request_produces_a_server_span_with_the_method_and_status(): void
    {
        $middleware = new RequestSpanMiddleware($this->tracerProvider);

        $response = $middleware->process(
            new ServerRequest('GET', 'https://app.test/orders/42'),
            self::handlerReturning(new Response(200)),
        );

        self::assertSame(200, $response->getStatusCode());
        $span = $this->span();
        self::assertSame('GET', $span->getName());
        self::assertSame(SpanKind::KIND_SERVER, $span->getKind());
        self::assertSame('GET', $span->getAttributes()->get('http.request.method'));
        self::assertNull($span->getAttributes()->get('url.path'));
        self::assertSame(200, $span->getAttributes()->get('http.response.status_code'));
        self::assertIsInt($span->getAttributes()->get('php.memory.usage'));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    public function test_a_5xx_response_marks_the_span_as_an_error(): void
    {
        new RequestSpanMiddleware($this->tracerProvider)->process(
            new ServerRequest('GET', 'https://app.test/'),
            self::handlerReturning(new Response(503)),
        );

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_an_exception_is_recorded_and_rethrown(): void
    {
        $middleware = new RequestSpanMiddleware($this->tracerProvider);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('controller exploded');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', 'https://app.test/'), $handler);
            self::fail('Expected the exception to propagate.');
        } catch (RuntimeException) {
        }

        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(RuntimeException::class, $span->getStatus()->getDescription());
        self::assertNotSame([], $span->getEvents());
    }

    public function test_an_incoming_traceparent_header_parents_the_span(): void
    {
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $parentSpanId = 'b7ad6b7169203331';
        $request = new ServerRequest('GET', 'https://app.test/', [
            'Traceparent' => "00-{$traceId}-{$parentSpanId}-01",
        ]);

        new RequestSpanMiddleware($this->tracerProvider)->process($request, self::handlerReturning(new Response(200)));

        $span = $this->span();
        self::assertSame($traceId, $span->getTraceId());
        self::assertSame($parentSpanId, $span->getParentSpanId());
    }

    public function test_the_span_is_current_while_the_handler_runs(): void
    {
        $observedTraceId = null;
        $handler = new class($observedTraceId) implements RequestHandlerInterface {
            public function __construct(private ?string &$observedTraceId) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->observedTraceId = Span::getCurrent()->getContext()->getTraceId();

                return new Response(200);
            }
        };

        new RequestSpanMiddleware($this->tracerProvider)->process(new ServerRequest('GET', 'https://app.test/'), $handler);

        self::assertSame($this->span()->getTraceId(), $observedTraceId);
    }

    private static function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
