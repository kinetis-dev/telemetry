<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Instrumentation;

use Kinetis\Http\Routing\Router;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Telemetry\Instrumentation\OtelTelemetry;
use Kinetis\Telemetry\Tests\Fixtures\FailingController;
use Kinetis\Telemetry\Tests\TracingTestCase;
use Kinetis\Testing\TestApplication;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

/**
 * The request span through a real `Kernel::handle()`: it encloses the
 * framework's fixed global middleware, so the whole request is one
 * trace under the caller's `traceparent`.
 */
final class KernelRequestSpanTest extends TracingTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Telemetry::global()->swap(new OtelTelemetry($this->tracerProvider));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_one_server_span_parents_the_fixed_middleware_under_the_callers_trace(): void
    {
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $callerSpanId = 'b7ad6b7169203331';
        $router = new Router();
        $router->register(FailingController::class);
        $app = TestApplication::withRouter($router);

        try {
            $app->client()->get('/fail', headers: ['traceparent' => "00-{$traceId}-{$callerSpanId}-01"])->assertStatus(500);
        } finally {
            $app->dispose();
        }

        $servers = array_values(array_filter(
            $this->spans(),
            static fn (ImmutableSpan $span): bool => $span->getKind() === SpanKind::KIND_SERVER,
        ));
        self::assertCount(1, $servers);
        [$server] = $servers;
        self::assertSame($traceId, $server->getTraceId());
        self::assertSame($callerSpanId, $server->getParentSpanId());
        self::assertSame(500, $server->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_ERROR, $server->getStatus()->getCode());

        $securityHeaders = $this->named('middleware SecurityHeadersMiddleware');
        $exceptionHandler = $this->named('middleware ExceptionHandlerMiddleware');
        $requestBody = $this->named('middleware RequestBodyMiddleware');
        self::assertSame($server->getSpanId(), $securityHeaders->getParentSpanId());
        self::assertSame($securityHeaders->getSpanId(), $exceptionHandler->getParentSpanId());
        self::assertSame($exceptionHandler->getSpanId(), $requestBody->getParentSpanId());

        foreach ($this->spans() as $span) {
            self::assertSame($traceId, $span->getTraceId(), $span->getName() . ' left the request trace');
        }
    }

    private function named(string $name): ImmutableSpan
    {
        foreach ($this->spans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        self::fail('No exported span named ' . $name);
    }
}
