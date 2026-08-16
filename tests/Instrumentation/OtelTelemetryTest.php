<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Instrumentation;

use Kinetis\Telemetry\Instrumentation\OtelTelemetry;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

final class OtelTelemetryTest extends TracingTestCase
{
    private OtelTelemetry $telemetry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->telemetry = new OtelTelemetry($this->tracerProvider);
    }

    public function test_a_phase_becomes_a_span_with_the_reported_timestamps(): void
    {
        $this->telemetry->phase('bootstrap.discovery', 100.0, 100.25);

        $span = $this->span();
        self::assertSame('bootstrap.discovery', $span->getName());
        self::assertSame(100_000_000_000, $span->getStartEpochNanos());
        self::assertSame(100_250_000_000, $span->getEndEpochNanos());
    }

    public function test_a_query_span_is_named_by_keyword_and_marks_the_server_start(): void
    {
        $token = $this->telemetry->queryDispatched('mysql', 'SELECT * FROM orders');
        $this->telemetry->queryServerStarted($token);
        $this->telemetry->queryReaped($token, null);

        $span = $this->span();
        self::assertSame('SELECT', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('mysql', $span->getAttributes()->get('db.system.name'));
        self::assertCount(1, $span->getEvents());
        self::assertSame('server.started', $span->getEvents()[0]->getName());
    }

    public function test_a_failed_query_marks_the_span_as_an_error(): void
    {
        $token = $this->telemetry->queryDispatched('postgresql', 'SELECT 1');
        $this->telemetry->queryReaped($token, new RuntimeException('no such table'));

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_an_activated_hook_parents_spans_started_inside_it(): void
    {
        $controller = $this->telemetry->controllerInvoked('App\\OrderController', 'show');
        $query = $this->telemetry->queryDispatched('mysql', 'SELECT 1');
        $this->telemetry->queryReaped($query, null);
        $this->telemetry->controllerReturned($controller, null);

        [$querySpan, $controllerSpan] = $this->spans();
        self::assertSame('OrderController::show', $controllerSpan->getName());
        self::assertSame($controllerSpan->getSpanId(), $querySpan->getParentSpanId());
    }

    public function test_a_query_span_never_becomes_the_parent_of_a_concurrent_sibling(): void
    {
        $first = $this->telemetry->queryDispatched('mysql', 'SELECT 1');
        $second = $this->telemetry->queryDispatched('mysql', 'SELECT 2');
        $this->telemetry->queryReaped($first, null);
        $this->telemetry->queryReaped($second, null);

        [$a, $b] = $this->spans();
        self::assertNotSame($a->getSpanId(), $b->getParentSpanId());
    }

    public function test_a_transaction_span_records_its_outcome(): void
    {
        $token = $this->telemetry->transactionStarted('mysql');
        $this->telemetry->transactionEnded($token, 'rollback');

        $span = $this->span();
        self::assertSame('transaction', $span->getName());
        self::assertSame('rollback', $span->getAttributes()->get('db.transaction.outcome'));
    }

    public function test_a_job_span_carries_queue_class_attempt_and_outcome(): void
    {
        $token = $this->telemetry->jobStarted('App\\SendEmail', 'emails', 2);
        $this->telemetry->jobFinished($token, 'release', new RuntimeException('smtp down'));

        $span = $this->span();
        self::assertSame('emails process', $span->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $span->getKind());
        self::assertSame(2, $span->getAttributes()->get('kinetis.job.attempt'));
        self::assertSame('release', $span->getAttributes()->get('kinetis.job.outcome'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    public function test_middleware_and_route_match_hooks_produce_named_spans(): void
    {
        $middleware = $this->telemetry->middlewareEntered('Kinetis\\Http\\Middleware\\ExceptionHandlerMiddleware');
        $match = $this->telemetry->routeMatchStarted('GET', '/orders/7');
        $this->telemetry->routeMatchEnded($match, '/orders/{id}');
        $this->telemetry->middlewareExited($middleware, null);

        [$matchSpan, $middlewareSpan] = $this->spans();
        self::assertSame('route.match', $matchSpan->getName());
        self::assertSame('/orders/{id}', $matchSpan->getAttributes()->get('http.route'));
        self::assertSame('middleware ExceptionHandlerMiddleware', $middlewareSpan->getName());
        self::assertSame($middlewareSpan->getSpanId(), $matchSpan->getParentSpanId());
    }

    public function test_an_ended_call_with_a_foreign_token_is_ignored(): void
    {
        $this->telemetry->queryReaped(null, null);
        $this->telemetry->middlewareExited('not-a-token', null);

        self::assertSame([], $this->spans());
    }

    public function test_task_hooks_nest_under_the_batch(): void
    {
        $batch = $this->telemetry->taskBatchStarted(2);
        $task = $this->telemetry->taskStarted(0);
        $this->telemetry->taskEnded($task, null);
        $this->telemetry->taskBatchEnded($batch);

        [$taskSpan, $batchSpan] = $this->spans();
        self::assertSame('concurrently', $batchSpan->getName());
        self::assertSame($batchSpan->getSpanId(), $taskSpan->getParentSpanId());
    }
}
