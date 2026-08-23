<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Kinetis\Session\SessionStoreInterface;
use RuntimeException;

/**
 * An in-memory session store; `$failWith` makes every call throw
 * instead, for the error path.
 */
final class FakeSessionStore implements SessionStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    public function __construct(private readonly ?string $failWith = null) {}

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        $this->guard();

        return $this->data[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->guard();
        $this->data[$id] = $data;
    }

    #[\Override]
    public function destroy(string $id): void
    {
        $this->guard();
        unset($this->data[$id]);
    }

    private function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
