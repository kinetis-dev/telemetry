<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * An in-memory PSR-16 cache; `$failWith` makes every call throw
 * instead, for the error path.
 */
final class FakeSimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly ?string $failWith = null) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->guard();

        return $this->data[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->guard();
        $this->data[$key] = $value;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $this->guard();
        unset($this->data[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->guard();
        $this->data = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $this->guard();

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->data[$key] ?? $default;
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $this->guard();

        foreach ($values as $key => $value) {
            $this->data[$key] = $value;
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $this->guard();

        foreach ($keys as $key) {
            unset($this->data[$key]);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        $this->guard();

        return array_key_exists($key, $this->data);
    }

    private function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
