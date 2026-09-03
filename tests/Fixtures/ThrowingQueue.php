<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;
use RuntimeException;

/**
 * A `QueueInterface` whose operations throw instead of succeeding,
 * selected by name — the real error shape a backend (a dropped Redis
 * connection, a failed SQL `UPDATE`) can produce for any one of
 * push/ack/release/fail independently of the others.
 */
final class ThrowingQueue implements QueueInterface
{
    /** @var list<string> */
    public array $events = [];

    /**
     * @param list<string> $failOn method names ('push', 'ack', 'release', 'fail') that throw
     */
    public function __construct(
        private readonly array $failOn = [],
        private readonly ?QueuedJob $next = null,
    ) {}

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->guard('push');
    }

    /**
     * @param list<string> $queues
     */
    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        return $this->next;
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        $this->guard('ack');
    }

    #[\Override]
    public function release(QueuedJob $job): void
    {
        $this->guard('release');
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        $this->guard('fail');
    }

    #[\Override]
    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        return 0;
    }

    private function guard(string $operation): void
    {
        $this->events[] = $operation;

        if (in_array($operation, $this->failOn, true)) {
            throw new RuntimeException("{$operation} failed");
        }
    }
}
