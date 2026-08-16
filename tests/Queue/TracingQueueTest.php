<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Queue;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Telemetry\Queue\TracingQueue;
use Kinetis\Telemetry\Tests\Fixtures\FakeQueue;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

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

    public function test_size_and_clear_delegate_without_spans(): void
    {
        $queue = new TracingQueue(new FakeQueue(), $this->tracerProvider);

        self::assertSame(7, $queue->size());
        self::assertSame(3, $queue->clear());
        self::assertSame([], $this->spans());
    }
}
