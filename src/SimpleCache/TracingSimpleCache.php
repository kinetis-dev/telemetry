<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\SimpleCache;

use DateInterval;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * A span per cache operation, wrapping any PSR-16 `CacheInterface` —
 * `kinetis/cache-redis`'s `RedisSimpleCache`/`ClusteredRedisSimpleCache`
 * included. Register it around whatever the cache package's own
 * bootstrap bound:
 *
 *     $app->instance(CacheInterface::class, new TracingSimpleCache(
 *         RedisSimpleCache::fromConfig($config),
 *         $app->get(TracerProviderInterface::class),
 *     ));
 *
 * Keys travel as span attributes — they are structured identifiers
 * (`user:123:profile`), not free-text input — but values never do, the
 * same discipline the SQL decorators apply to bound parameters.
 *
 * Spans are not activated: they read the current context (normally the
 * request span) as parent and end immediately, so concurrent cache
 * calls inside `concurrently()` never interleave anyone's scope stack.
 */
final class TracingSimpleCache implements CacheInterface
{
    private readonly TracerInterface $tracer;

    public function __construct(
        private readonly CacheInterface $inner,
        TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->traced('get', [$key], fn (): mixed => $this->inner->get($key, $default));
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        return $this->traced('set', [$key], fn (): bool => $this->inner->set($key, $value, $ttl));
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return $this->traced('delete', [$key], fn (): bool => $this->inner->delete($key));
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->traced('clear', [], fn (): bool => $this->inner->clear());
    }

    /**
     * $keys is materialized once, into $keyList, and it's $keyList —
     * never the original $keys — that's both recorded on the span and
     * delegated to $inner: a Generator or any other non-rewindable
     * Iterator is exhausted the moment `iterator_to_array()` reads it,
     * so passing the original $keys through to $inner afterward would
     * hand it nothing left to yield.
     *
     * @param iterable<string> $keys
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyList = array_values(is_array($keys) ? $keys : iterator_to_array($keys, false));

        return $this->traced('getMultiple', $keyList, fn (): iterable => $this->inner->getMultiple($keyList, $default));
    }

    /**
     * $values is materialized once, into $valueMap, preserving its own
     * keys (the cache keys PSR-16's `setMultiple()` reads from
     * `$values`' own iteration keys, not its values) — $valueMap is
     * what's delegated to $inner, for the identical exhausted-iterable
     * reason `getMultiple()`'s own docblock explains.
     *
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $valueMap = is_array($values) ? $values : iterator_to_array($values);
        $keyList = array_keys($valueMap);

        return $this->traced('setMultiple', $keyList, fn (): bool => $this->inner->setMultiple($valueMap, $ttl));
    }

    /**
     * @param iterable<string> $keys
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keyList = array_values(is_array($keys) ? $keys : iterator_to_array($keys, false));

        return $this->traced('deleteMultiple', $keyList, fn (): bool => $this->inner->deleteMultiple($keyList));
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->traced('has', [$key], fn (): bool => $this->inner->has($key));
    }

    /**
     * @template T
     * @param non-empty-string $operation
     * @param list<string> $keys
     * @param callable(): T $run
     * @return T
     */
    private function traced(string $operation, array $keys, callable $run): mixed
    {
        $builder = $this->tracer->spanBuilder($operation)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', 'redis')
            ->setAttribute('db.operation.name', $operation);

        if ($keys !== []) {
            $builder->setAttribute('db.keys', $keys);
        }

        $span = $builder->startSpan();

        try {
            return $run();
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }
}
