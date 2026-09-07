<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\FiberBoundContextStorageExecutionAwareBC;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Collects finished spans in memory, over a fresh instance of the
 * Fiber-bound context storage OpenTelemetry installs by default — the
 * storage production runs on, reset per test so no scope stack survives
 * from one to the next.
 */
abstract class TracingTestCase extends TestCase
{
    protected InMemoryExporter $exporter;

    protected TracerProvider $tracerProvider;

    #[\Override]
    protected function setUp(): void
    {
        Context::setStorage(new FiberBoundContextStorageExecutionAwareBC());
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    }

    /**
     * @return list<ImmutableSpan>
     */
    protected function spans(): array
    {
        /** @var list<ImmutableSpan> */
        return $this->exporter->getSpans();
    }

    protected function span(int $index = 0): ImmutableSpan
    {
        $spans = $this->spans();
        self::assertArrayHasKey($index, $spans, 'Expected a finished span at index ' . $index);

        return $spans[$index];
    }
}
