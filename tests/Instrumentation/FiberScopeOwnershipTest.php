<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Instrumentation;

use ErrorException;
use Fiber;
use Kinetis\Async\Timer;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Telemetry\Instrumentation\OtelTelemetry;
use Kinetis\Telemetry\Middleware\RequestSpanMiddleware;
use Kinetis\Telemetry\Tests\TracingTestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function Kinetis\Async\concurrently;

/**
 * Scope ownership under real Fibers: `concurrently()` tasks run on
 * Fibers from `FiberPool`, and a runtime may hand the HTTP pipeline a
 * Fiber of its own.
 *
 * Every diagnostic OpenTelemetry raises about context handling — access
 * to an uninitialized Fiber context, and (with assertions on, which is
 * how these run) `DebugScope`'s report of a scope detached out of order,
 * from the wrong execution context, or twice — is captured here as well
 * as thrown. Capturing is the load-bearing half:
 * `Kinetis\Instrumentation\Telemetry` contains anything a hook throws,
 * so an exception alone would be swallowed and the defect would pass.
 */
final class FiberScopeOwnershipTest extends TracingTestCase
{
    /** @var list<string> */
    private array $diagnostics = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->diagnostics = [];

        set_error_handler(
            function (int $severity, string $message, string $file, int $line): bool {
                $this->diagnostics[] = $message;

                throw new ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE,
        );

        Telemetry::global()->swap(new OtelTelemetry($this->tracerProvider));
    }

    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
        restore_error_handler();

        self::assertSame([], $this->diagnostics, 'OpenTelemetry reported a context or scope diagnostic.');
    }

    public function test_overlapping_tasks_keep_their_nested_spans_in_their_own_subtree(): void
    {
        concurrently([
            function (): void {
                $this->dispatchEventFor('App\\Slow', 0.04);
            },
            function (): void {
                $this->dispatchEventFor('App\\Fast', 0.01);
            },
        ]);

        $batch = $this->named('concurrently');
        $first = $this->named('task 0');
        $second = $this->named('task 1');
        $slow = $this->named('event Slow');
        $fast = $this->named('event Fast');

        // The two nested spans are alive at the same time, so a single
        // shared scope stack would have to hold both at once.
        self::assertLessThan($slow->getEndEpochNanos(), $fast->getStartEpochNanos());
        self::assertLessThan($fast->getEndEpochNanos(), $slow->getStartEpochNanos());

        self::assertSame($first->getSpanId(), $slow->getParentSpanId());
        self::assertSame($second->getSpanId(), $fast->getParentSpanId());
        self::assertSame($batch->getSpanId(), $first->getParentSpanId());
        self::assertSame($batch->getSpanId(), $second->getParentSpanId());
    }

    public function test_a_nested_batch_and_its_task_stay_under_the_outer_task(): void
    {
        concurrently([
            static function (): void {
                concurrently([static function (): void {
                    Timer::delay(0.01);
                }]);
            },
        ]);

        self::assertSame(
            ['task 0', 'concurrently', 'task 0', 'concurrently'],
            array_map(static fn (ImmutableSpan $span): string => $span->getName(), $this->spans()),
        );
        [$innerTask, $innerBatch, $outerTask, $outerBatch] = $this->spans();

        self::assertSame($innerBatch->getSpanId(), $innerTask->getParentSpanId());
        self::assertSame($outerTask->getSpanId(), $innerBatch->getParentSpanId());
        self::assertSame($outerBatch->getSpanId(), $outerTask->getParentSpanId());
    }

    public function test_the_request_span_starts_on_a_fresh_fiber_from_an_incoming_or_root_parent(): void
    {
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $callerSpanId = 'b7ad6b7169203331';
        $middleware = new RequestSpanMiddleware($this->tracerProvider);
        $handler = self::handlerReturning(new Response(200));

        self::runInFiber(static function () use ($middleware, $handler, $traceId, $callerSpanId): void {
            $middleware->process(
                new ServerRequest('GET', 'https://app.test/', ['Traceparent' => "00-{$traceId}-{$callerSpanId}-01"]),
                $handler,
            );
        });
        self::runInFiber(static function () use ($middleware, $handler): void {
            $middleware->process(new ServerRequest('POST', 'https://app.test/'), $handler);
        });

        [$propagated, $rooted] = $this->spans();
        self::assertSame($traceId, $propagated->getTraceId());
        self::assertSame($callerSpanId, $propagated->getParentSpanId());
        self::assertSame('0000000000000000', $rooted->getParentSpanId());
    }

    public function test_the_callers_context_survives_a_batch_that_succeeds_and_one_that_fails(): void
    {
        $caller = Context::getCurrent();

        concurrently([static fn (): int => 1]);

        self::assertSame($caller, Context::getCurrent());
        self::assertNull(Context::storage()->scope());

        try {
            concurrently([
                static function (): void {
                    Timer::delay(0.01);
                },
                static fn (): never => throw new RuntimeException('task exploded'),
            ]);
            self::fail('Expected the task failure to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame($caller, Context::getCurrent());
        self::assertNull(Context::storage()->scope());

        // A resident Fiber is reused across batches: the task it runs
        // next parents to that batch, not to what an earlier one left.
        concurrently([static fn (): int => 1]);

        [$task, $batch] = \array_slice($this->spans(), -2);
        self::assertSame(['task 0', 'concurrently'], [$task->getName(), $batch->getName()]);
        self::assertSame($batch->getSpanId(), $task->getParentSpanId());
    }

    private function dispatchEventFor(string $eventClass, float $delay): void
    {
        $telemetry = Telemetry::global();
        $token = $telemetry->eventDispatched($eventClass);
        Timer::delay($delay);
        $telemetry->eventSettled($token);
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

    private static function runInFiber(callable $body): void
    {
        new Fiber($body)->start();
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
