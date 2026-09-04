<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;

/** Hands back a fixed QueuedJob per pop() and records what happened. */
final class FakeQueue implements ClearableQueueInterface
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly ?QueuedJob $next = null) {}

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->events[] = "push:{$queue}:" . $job::class;
    }

    /**
     * @param list<string> $queues
     */
    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        $this->events[] = 'pop';

        return $this->next;
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        $this->events[] = 'ack';
    }

    #[\Override]
    public function release(QueuedJob $job): void
    {
        $this->events[] = 'release';
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        $this->events[] = 'fail';
    }

    #[\Override]
    public function size(string $queue = 'default'): int
    {
        return 7;
    }

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        return 3;
    }
}
