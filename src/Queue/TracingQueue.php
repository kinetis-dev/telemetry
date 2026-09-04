<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Queue;

use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;
use WeakMap;

/**
 * Spans around any queue backend. `push()` gets a producer span; a
 * consumer span opens when `pop()` hands a job over and closes when the
 * same QueuedJob reaches `ack()`, `release()`, or `fail()` — so its
 * duration is the job's real processing time, and it carries the
 * outcome. Register it around whatever the queue package's own
 * bootstrap bound:
 *
 *     $app->instance(QueueInterface::class, TracingQueue::wrap(
 *         QueueFactory::fromConfig($config),
 *         $app->get(TracerProviderInterface::class),
 *     ));
 *
 * wrap(), not `new`: a backend declaring
 * Kinetis\Queue\ClearableQueueInterface keeps that capability through
 * the decorator, via {@see ClearableTracingQueue}. This class covers
 * QueueInterface alone, so constructing it directly around a clearable
 * backend hides the clearing the backend can still do. Where the
 * backend's own type already says it clears, {@see wrapClearable()}
 * says so on the way out too, with nothing for the caller to narrow.
 *
 * The consumer span is activated for the job's duration, so spans a
 * job's own handle() produces — queries, HTTP calls — nest under it.
 * The worker loop runs pop → handle → ack strictly nested in one
 * fiber, which is what makes holding the scope across calls safe.
 *
 * Not final, and this is the only reason: {@see ClearableTracingQueue}
 * extends it to add clear() to the interfaces it declares. No method
 * here is written to be overridden.
 *
 * The consumer span joins the producer's trace whenever the job carries
 * propagation metadata — stored at push() time by the framework's
 * instrumentation hooks. The decorator's own push() cannot inject that
 * metadata (it has no way to reach the payload), so producer-side
 * propagation is hook-only; this decorator honors what it finds.
 */
class TracingQueue implements QueueInterface
{
    private readonly TracerInterface $tracer;

    /** @var WeakMap<QueuedJob, array{span: SpanInterface, scope: ScopeInterface}> */
    private WeakMap $consuming;

    public function __construct(
        private readonly QueueInterface $inner,
        TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
        $this->consuming = new WeakMap();
    }

    /**
     * Wraps $inner in spans, preserving whatever capabilities beyond
     * QueueInterface it declares — ClearableQueueInterface being the
     * only one, which a decorator can carry only by being a different
     * class: the interfaces a class declares are fixed at compile time,
     * while what it wraps is not.
     *
     * The return type follows the argument's, so wrapping a queue whose
     * own type already says it clears yields one that still does. A
     * plain QueueInterface argument — QueueFactory::fromConfig()'s own
     * result, where the backend is a configuration value — yields a
     * plain QueueInterface, matching what is actually known: the
     * capability is there at run time whenever the backend has it, and
     * a caller that needs it in the type either passes a queue already
     * typed for it or calls wrapClearable().
     *
     * @template TQueue of QueueInterface
     * @param TQueue $inner
     * @return (TQueue is ClearableQueueInterface ? ClearableQueueInterface : QueueInterface)
     */
    public static function wrap(QueueInterface $inner, TracerProviderInterface $tracerProvider): QueueInterface
    {
        return $inner instanceof ClearableQueueInterface
            ? new ClearableTracingQueue($inner, $tracerProvider)
            : new self($inner, $tracerProvider);
    }

    /**
     * wrap() for a backend whose own type already declares clearing —
     * the same decorator, reached without a conditional type to read:
     * an argument that does not clear is a compile-time error here
     * rather than a capability quietly missing from the result.
     */
    public static function wrapClearable(
        ClearableQueueInterface $inner,
        TracerProviderInterface $tracerProvider,
    ): ClearableQueueInterface {
        return new ClearableTracingQueue($inner, $tracerProvider);
    }

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $span = $this->tracer->spanBuilder("{$queue} publish")
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('messaging.destination.name', $queue)
            ->setAttribute('kinetis.job.class', $job::class)
            ->startSpan();

        try {
            $this->inner->push($job, $delaySeconds, $queue, $maxAttempts);
        } catch (Throwable $e) {
            Redaction::recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @param list<string> $queues
     */
    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        $job = $this->inner->pop($timeoutSeconds, $queues);

        if ($job === null) {
            return null;
        }

        $builder = $this->tracer->spanBuilder("{$job->queue} process");

        // Metadata the hooks stored at push() time parents this span into
        // the producer's trace; the decorator itself still cannot inject
        // on its own push() — that path stays hook-only.
        if ($job->metadata !== []) {
            $builder->setParent(TraceContextPropagator::getInstance()->extract($job->metadata));
        }

        $span = $builder
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.destination.name', $job->queue)
            ->setAttribute('kinetis.job.class', $job->class)
            ->setAttribute('kinetis.job.attempt', $job->attempts)
            ->startSpan();

        $this->consuming[$job] = ['span' => $span, 'scope' => $span->activate()];

        return $job;
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        try {
            $this->inner->ack($job);
        } catch (Throwable $e) {
            $this->finishWithError($job, 'ack_error', $e);

            throw $e;
        }

        $this->finish($job, 'ack');
    }

    #[\Override]
    public function release(QueuedJob $job): void
    {
        try {
            $this->inner->release($job);
        } catch (Throwable $e) {
            $this->finishWithError($job, 'release_error', $e);

            throw $e;
        }

        $this->finish($job, 'release');
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        try {
            $this->inner->fail($job);
        } catch (Throwable $e) {
            $this->finishWithError($job, 'fail_error', $e);

            throw $e;
        }

        // The job itself is what failed here, not this operation — the
        // span still reports an error outcome even though $inner->fail()
        // itself completed successfully; that's the whole point of
        // calling fail() in the first place.
        $span = $this->consuming[$job]['span'] ?? null;
        $span?->setStatus(StatusCode::STATUS_ERROR);
        $this->finish($job, 'fail');
    }

    #[\Override]
    public function size(string $queue = 'default'): int
    {
        return $this->inner->size($queue);
    }

    /**
     * Records $e on $job's own consumer span (if this decorator is the
     * one that popped it — a job it never saw simply has nothing to
     * record onto, matching `finish()`'s own no-op for that case) and
     * closes it with $outcome instead of whatever the calling method's
     * own successful outcome value would have been, so an infrastructure
     * failure calling into $inner can never be exported looking like the
     * terminal operation it was attempting actually succeeded.
     */
    private function finishWithError(QueuedJob $job, string $outcome, Throwable $e): void
    {
        $span = $this->consuming[$job]['span'] ?? null;

        if ($span !== null) {
            Redaction::recordFailure($span, $e);
        }

        $this->finish($job, $outcome);
    }

    private function finish(QueuedJob $job, string $outcome): void
    {
        if (!isset($this->consuming[$job])) {
            return;
        }

        ['span' => $span, 'scope' => $scope] = $this->consuming[$job];
        unset($this->consuming[$job]);

        $span->setAttribute('kinetis.job.outcome', $outcome);
        $scope->detach();
        $span->end();
    }
}
