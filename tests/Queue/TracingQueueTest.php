<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Queue;

use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Telemetry\Queue\ClearableTracingQueue;
use Kinetis\Telemetry\Queue\TracingQueue;
use Kinetis\Telemetry\Tests\Fixtures\FakeQueue;
use Kinetis\Telemetry\Tests\Fixtures\ThrowingQueue;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

final class TracingQueueTest extends TracingTestCase
{
    public function test_push_produces_a_producer_span_naming_queue_and_job(): void
    {
        $inner = new FakeQueue();
        $queue = new TracingQueue($inner, $this->tracerProvider);
        $job = new class implements Job {};

        $queue->push($job, queue: 'emails');

        $span = $this->span();
        self::assertSame('emails publish', $span->getName());
        self::assertSame(SpanKind::KIND_PRODUCER, $span->getKind());
        self::assertSame('emails', $span->getAttributes()->get('messaging.destination.name'));
        self::assertSame($job::class, $span->getAttributes()->get('kinetis.job.class'));
        self::assertSame(['push:emails:' . $job::class], $inner->events);
    }

    public function test_a_consumer_span_runs_from_pop_to_ack_and_records_the_outcome(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'emails', attempts: 2);
        $queue = new TracingQueue(new FakeQueue($queuedJob), $this->tracerProvider);

        $popped = $queue->pop();
        self::assertSame($queuedJob, $popped);
        self::assertSame([], $this->spans(), 'The consumer span must stay open until the job resolves.');

        $queue->ack($queuedJob);

        $span = $this->span();
        self::assertSame('emails process', $span->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $span->getKind());
        self::assertSame('App\\SendEmail', $span->getAttributes()->get('kinetis.job.class'));
        self::assertSame(2, $span->getAttributes()->get('kinetis.job.attempt'));
        self::assertSame('ack', $span->getAttributes()->get('kinetis.job.outcome'));
    }

    public function test_work_between_pop_and_ack_nests_under_the_consumer_span(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default');
        $queue = new TracingQueue(new FakeQueue($queuedJob), $this->tracerProvider);

        $queue->pop();
        $duringJob = Span::getCurrent()->getContext();
        $queue->ack($queuedJob);
        $afterJob = Span::getCurrent()->getContext();

        self::assertTrue($duringJob->isValid());
        self::assertSame($this->span()->getSpanId(), $duringJob->getSpanId());
        self::assertFalse($afterJob->isValid());
    }

    public function test_fail_marks_the_span_as_an_error(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default');
        $queue = new TracingQueue(new FakeQueue($queuedJob), $this->tracerProvider);

        $queue->pop();
        $queue->fail($queuedJob);

        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('fail', $span->getAttributes()->get('kinetis.job.outcome'));
    }

    public function test_release_records_its_own_outcome(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default');
        $queue = new TracingQueue(new FakeQueue($queuedJob), $this->tracerProvider);

        $queue->pop();
        $queue->release($queuedJob);

        self::assertSame('release', $this->span()->getAttributes()->get('kinetis.job.outcome'));
    }

    public function test_an_empty_pop_produces_no_span(): void
    {
        $queue = new TracingQueue(new FakeQueue(), $this->tracerProvider);

        self::assertNull($queue->pop());
        self::assertSame([], $this->spans());
    }

    public function test_ack_for_a_job_this_instance_never_popped_still_delegates(): void
    {
        $inner = new FakeQueue();
        $queue = new TracingQueue($inner, $this->tracerProvider);

        $queue->ack(new QueuedJob('App\\SendEmail', [], null, 'default'));

        self::assertSame(['ack'], $inner->events);
        self::assertSame([], $this->spans());
    }

    public function test_a_popped_job_with_metadata_joins_the_producers_trace(): void
    {
        $producerTelemetry = new \Kinetis\Telemetry\Instrumentation\OtelTelemetry($this->tracerProvider);
        $pushToken = $producerTelemetry->jobPushStarted('App\\SendEmail', 'default');
        $metadata = $producerTelemetry->jobPushMetadata($pushToken);
        $producerTelemetry->jobPushEnded($pushToken, null);

        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default', metadata: $metadata);
        $queue = new TracingQueue(new FakeQueue($queuedJob), $this->tracerProvider);

        $queue->pop();
        $queue->ack($queuedJob);

        [$producer, $consumer] = $this->spans();
        self::assertSame($producer->getTraceId(), $consumer->getTraceId());
    }

    public function test_size_delegates_without_spans(): void
    {
        $queue = new TracingQueue(new FakeQueue(), $this->tracerProvider);

        self::assertSame(7, $queue->size());
        self::assertSame([], $this->spans());
    }

    public function test_wrap_keeps_a_clearable_backend_clearable(): void
    {
        $queue = TracingQueue::wrap(new FakeQueue(), $this->tracerProvider);

        self::assertInstanceOf(ClearableTracingQueue::class, $queue);
        self::assertInstanceOf(ClearableQueueInterface::class, $queue);
        self::assertSame(3, $queue->clear());
        self::assertSame([], $this->spans());
    }

    public function test_wrap_leaves_a_backend_that_cannot_clear_unclearable(): void
    {
        $queue = TracingQueue::wrap(new ThrowingQueue([]), $this->tracerProvider);

        self::assertInstanceOf(TracingQueue::class, $queue);
        self::assertNotInstanceOf(ClearableQueueInterface::class, $queue);
    }

    /**
     * The decorator that clears is a TracingQueue, not a second wrapper
     * holding one, so a caller typed on TracingQueue keeps working and
     * there is one implementation of the job lifecycle to keep correct.
     */
    public function test_the_clearable_decorator_is_itself_a_tracing_queue(): void
    {
        $queue = TracingQueue::wrapClearable(new FakeQueue(), $this->tracerProvider);

        self::assertInstanceOf(TracingQueue::class, $queue);
        self::assertInstanceOf(ClearableTracingQueue::class, $queue);
    }

    /**
     * wrapClearable() says in its own signature what wrap() can only say
     * conditionally, so the result reaches a ClearableQueueInterface
     * parameter with nothing for the caller to narrow — a decorator that
     * stopped declaring the capability is a TypeError here.
     */
    public function test_wrap_clearable_returns_the_capability_type(): void
    {
        $queue = TracingQueue::wrapClearable(new FakeQueue(), $this->tracerProvider);

        self::assertSame(3, self::clearThrough($queue));
        self::assertSame([], $this->spans(), 'clear() is administrative and belongs to no job span.');
    }

    /**
     * wrap()'s result follows its argument's type, so a backend already
     * typed as clearable produces one that reaches the same parameter.
     */
    public function test_wrap_of_a_clearable_backend_reaches_the_capability_type(): void
    {
        $inner = new FakeQueue();

        self::assertSame(3, self::clearThrough(TracingQueue::wrap($inner, $this->tracerProvider)));
    }

    public function test_the_clearable_decorator_still_traces_the_job_lifecycle(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'emails', attempts: 2);
        $inner = new FakeQueue($queuedJob);
        $queue = TracingQueue::wrapClearable($inner, $this->tracerProvider);

        $queue->push(new class implements Job {}, queue: 'emails');

        $span = $this->span();
        self::assertSame('emails publish', $span->getName());
        self::assertSame(SpanKind::KIND_PRODUCER, $span->getKind());

        // The whole popped-to-settled arc, inherited rather than
        // re-implemented: a pass-through that forgot to open or close
        // the consumer span would leave nothing here to read.
        self::assertSame($queuedJob, $queue->pop());
        $queue->ack($queuedJob);

        $consumer = $this->span(1);
        self::assertSame('emails process', $consumer->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $consumer->getKind());
        self::assertSame('ack', $consumer->getAttributes()->get('kinetis.job.outcome'));
        self::assertSame(7, $queue->size());
    }

    /** Typed as the capability, which is what the tests above are for. */
    private static function clearThrough(ClearableQueueInterface $queue): int
    {
        return $queue->clear();
    }

    public function test_a_failing_push_records_the_exception_and_marks_the_span_as_an_error(): void
    {
        $queue = new TracingQueue(new ThrowingQueue(['push']), $this->tracerProvider);
        $job = new class implements Job {};

        try {
            $queue->push($job, queue: 'emails');

            self::fail('Expected the push failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('push failed', $e->getMessage());
        }

        self::assertCount(1, $this->spans());
        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNotSame([], $span->getEvents());
    }

    public function test_a_failing_ack_records_the_exception_marks_error_and_uses_a_failed_outcome(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default');
        $queue = new TracingQueue(new ThrowingQueue(['ack'], $queuedJob), $this->tracerProvider);

        $queue->pop();

        try {
            $queue->ack($queuedJob);

            self::fail('Expected the ack failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('ack failed', $e->getMessage());
        }

        self::assertCount(1, $this->spans());
        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNotSame([], $span->getEvents());
        self::assertSame('ack_error', $span->getAttributes()->get('kinetis.job.outcome'));

        // The consumer span's own scope is detached on the failure path
        // exactly as it is on success — no longer reported as current.
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function test_a_failing_release_records_the_exception_marks_error_and_uses_a_failed_outcome(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default');
        $queue = new TracingQueue(new ThrowingQueue(['release'], $queuedJob), $this->tracerProvider);

        $queue->pop();

        try {
            $queue->release($queuedJob);

            self::fail('Expected the release failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('release failed', $e->getMessage());
        }

        self::assertCount(1, $this->spans());
        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNotSame([], $span->getEvents());
        self::assertSame('release_error', $span->getAttributes()->get('kinetis.job.outcome'));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    /**
     * Distinct from the already-covered "the job itself failed" case
     * (`test_fail_marks_the_span_as_an_error`, where `$inner->fail()`
     * itself succeeds): here the backend's own `fail()` call is what
     * throws — an infrastructure failure, not the intended outcome —
     * and must be recorded and reported as its own distinct outcome.
     */
    public function test_a_failing_fail_records_the_exception_marks_error_and_uses_a_failed_outcome(): void
    {
        $queuedJob = new QueuedJob('App\\SendEmail', [], null, 'default');
        $queue = new TracingQueue(new ThrowingQueue(['fail'], $queuedJob), $this->tracerProvider);

        $queue->pop();

        try {
            $queue->fail($queuedJob);

            self::fail('Expected the fail failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('fail failed', $e->getMessage());
        }

        self::assertCount(1, $this->spans());
        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNotSame([], $span->getEvents());
        self::assertSame('fail_error', $span->getAttributes()->get('kinetis.job.outcome'));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    /**
     * A job this instance never popped has no span to touch at all —
     * `finishWithError()`'s own null-span guard must make that a safe
     * no-op rather than a crash, while the real exception still
     * propagates untouched.
     */
    public function test_a_failing_ack_for_a_job_this_instance_never_popped_still_propagates_with_no_span(): void
    {
        $inner = new ThrowingQueue(['ack']);
        $queue = new TracingQueue($inner, $this->tracerProvider);

        try {
            $queue->ack(new QueuedJob('App\\SendEmail', [], null, 'default'));

            self::fail('Expected the ack failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('ack failed', $e->getMessage());
        }

        self::assertSame(['ack'], $inner->events);
        self::assertSame([], $this->spans());
    }
}
