<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\SimpleCache;

use Kinetis\Telemetry\SimpleCache\TracingSimpleCache;
use Kinetis\Telemetry\Tests\Fixtures\FakeSimpleCache;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

final class TracingSimpleCacheTest extends TracingTestCase
{
    public function test_get_produces_a_client_span_named_by_the_operation(): void
    {
        $cache = new TracingSimpleCache(new FakeSimpleCache(), $this->tracerProvider);

        $cache->get('user:1:profile');

        $span = $this->span();
        self::assertSame('get', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('redis', $span->getAttributes()->get('db.system.name'));
        self::assertSame('get', $span->getAttributes()->get('db.operation.name'));
        self::assertSame(['user:1:profile'], $span->getAttributes()->get('db.keys'));
    }

    public function test_set_is_spanned_but_the_value_is_never_recorded(): void
    {
        $cache = new TracingSimpleCache(new FakeSimpleCache(), $this->tracerProvider);

        $cache->set('user:1:profile', ['ssn' => '000-00-0000']);

        $span = $this->span();
        self::assertSame('set', $span->getName());
        self::assertStringNotContainsString(
            '000-00-0000',
            var_export($span->getAttributes()->toArray(), true),
        );
    }

    public function test_get_multiple_records_every_key(): void
    {
        $cache = new TracingSimpleCache(new FakeSimpleCache(), $this->tracerProvider);

        iterator_to_array($cache->getMultiple(['a', 'b', 'c']));

        self::assertSame(['a', 'b', 'c'], $this->span()->getAttributes()->get('db.keys'));
    }

    public function test_clear_carries_no_key_attribute(): void
    {
        $cache = new TracingSimpleCache(new FakeSimpleCache(), $this->tracerProvider);

        $cache->clear();

        self::assertNull($this->span()->getAttributes()->get('db.keys'));
    }

    public function test_a_failing_operation_marks_the_span_as_an_error_and_rethrows(): void
    {
        $cache = new TracingSimpleCache(new FakeSimpleCache(failWith: 'connection refused'), $this->tracerProvider);

        try {
            $cache->get('user:1:profile');
            self::fail('Expected the cache exception to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_a_returned_value_reaches_the_caller_unmodified(): void
    {
        $cache = new TracingSimpleCache(new FakeSimpleCache(), $this->tracerProvider);
        $cache->set('key', 'value');

        self::assertSame('value', $cache->get('key'));
        self::assertTrue($cache->has('key'));

        $cache->delete('key');
        self::assertFalse($cache->has('key'));
    }
}
