<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Queue;

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
 *     $app->instance(QueueInterface::class, new TracingQueue(
 *         QueueFactory::fromConfig($config),
 *         $app->get(TracerProviderInterface::class),
 *     ));
 *
 * The consumer span is activated for the job's duration, so spans a
 * job's own handle() produces — queries, HTTP calls — nest under it.
 * The worker loop runs pop → handle → ack strictly nested in one
 * fiber, which is what makes holding the scope across calls safe.
 *
 * The consumer span joins the producer's trace whenever the job carries
 * propagation metadata — stored at push() time by the framework's
 * instrumentation hooks. The decorator's own push() cannot inject that
 * metadata (it has no way to reach the payload), so producer-side
 * propagation is hook-only; this decorator honors what it finds.
 */
final class TracingQueue implements QueueInterface
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

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        return $this->inner->clear($queue);
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
