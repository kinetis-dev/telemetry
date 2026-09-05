<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Queue;

use Kinetis\Queue\ClearableQueueInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * {@see TracingQueue} for a backend that also declares
 * Kinetis\Queue\ClearableQueueInterface, so wrapping a Redis, SQL, or
 * RabbitMQ queue in spans does not cost it the ability to clear.
 *
 * A subclass rather than a second decorator holding a TracingQueue: the
 * job lifecycle is traced by exactly one implementation, inherited
 * whole, and this class adds one method and one interface. Duplicating
 * push()/pop()/ack()/release()/fail()/size() as pass-throughs would put
 * a second copy of the decorator's surface in reach of drifting from
 * the first.
 *
 * clear() reaches the backend directly, untraced: it is an
 * administrative operation on a queue rather than a step in a job's
 * life, so there is no span for it to belong to — the same reason
 * TracingQueue never traced size() either.
 *
 * One backend is passed in and both halves read from it, so there is no
 * way to pair a traced queue with an unrelated clearable one. Reach it
 * through {@see TracingQueue::wrapClearable()}, or {@see
 * TracingQueue::wrap()} where the backend's capability is only known at
 * run time.
 */
final class ClearableTracingQueue extends TracingQueue implements ClearableQueueInterface
{
    public function __construct(
        private readonly ClearableQueueInterface $clearable,
        TracerProviderInterface $tracerProvider,
    ) {
        parent::__construct($clearable, $tracerProvider);
    }

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        return $this->clearable->clear($queue);
    }
}
