<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Logging;

use Kinetis\Telemetry\Logging\TraceAwareLogger;
use Kinetis\Telemetry\Tests\TracingTestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class TraceAwareLoggerTest extends TracingTestCase
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    private array $entries = [];

    public function test_log_context_carries_the_active_spans_trace_and_span_ids(): void
    {
        $logger = new TraceAwareLogger($this->recorder());

        $span = $this->tracerProvider->getTracer('test')->spanBuilder('op')->startSpan();
        $scope = $span->activate();

        try {
            $logger->error('it broke', ['detail' => 'x']);
        } finally {
            $scope->detach();
            $span->end();
        }

        $context = $this->entries[0]['context'];
        self::assertSame('x', $context['detail']);
        self::assertSame($this->span()->getTraceId(), $context['trace_id']);
        self::assertSame($this->span()->getSpanId(), $context['span_id']);
    }

    public function test_without_an_active_span_the_context_passes_through_untouched(): void
    {
        new TraceAwareLogger($this->recorder())->info('hello', ['a' => 1]);

        self::assertSame(['a' => 1], $this->entries[0]['context']);
    }

    public function test_every_psr3_level_method_delegates(): void
    {
        $logger = new TraceAwareLogger($this->recorder());

        $logger->emergency('m');
        $logger->alert('m');
        $logger->critical('m');
        $logger->error('m');
        $logger->warning('m');
        $logger->notice('m');
        $logger->info('m');
        $logger->debug('m');
        $logger->log('info', 'm');

        self::assertCount(9, $this->entries);
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'info'],
            array_column($this->entries, 'level'),
        );
    }

    public function test_an_explicit_trace_id_in_context_is_not_overwritten(): void
    {
        $logger = new TraceAwareLogger($this->recorder());

        $span = $this->tracerProvider->getTracer('test')->spanBuilder('op')->startSpan();
        $scope = $span->activate();

        try {
            $logger->info('m', ['trace_id' => 'caller-supplied']);
        } finally {
            $scope->detach();
            $span->end();
        }

        self::assertSame('caller-supplied', $this->entries[0]['context']['trace_id']);
    }

    private function recorder(): AbstractLogger
    {
        $entries = &$this->entries;

        return new class($entries) extends AbstractLogger {
            /**
             * @param list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> $entries
             */
            public function __construct(private array &$entries) {}

            /**
             * @param array<string, mixed> $context
             */
            #[\Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }
}
