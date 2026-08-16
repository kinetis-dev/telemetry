<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Queue;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
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
 * Producer and consumer spans are separate traces: linking them needs
 * trace context inside the job payload, which a decorator has no way
 * to reach.
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

        $span = $this->tracer->spanBuilder("{$job->queue} process")
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
        } finally {
            $this->finish($job, 'ack');
        }
    }

    #[\Override]
    public function release(QueuedJob $job): void
    {
        try {
            $this->inner->release($job);
        } finally {
            $this->finish($job, 'release');
        }
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        try {
            $this->inner->fail($job);
        } finally {
            $span = $this->consuming[$job]['span'] ?? null;
            $span?->setStatus(StatusCode::STATUS_ERROR);
            $this->finish($job, 'fail');
        }
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
