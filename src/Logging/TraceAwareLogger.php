<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Logging;

use OpenTelemetry\API\Trace\Span;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Adds the active span's `trace_id`/`span_id` to every log entry's
 * context, so log lines join their trace in whatever backend receives
 * both. Wrap whatever logger the application already registers:
 *
 *     $app->instance(LoggerInterface::class, new TraceAwareLogger($realLogger));
 *
 * With no span recording — telemetry unconfigured, or a log call
 * outside any request — entries pass through untouched.
 */
final readonly class TraceAwareLogger implements LoggerInterface
{
    public function __construct(private LoggerInterface $inner) {}

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->inner->emergency($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->inner->alert($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->inner->critical($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->inner->error($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->inner->warning($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->inner->notice($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->inner->info($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->inner->debug($message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $this->withTrace($context));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function withTrace(array $context): array
    {
        $spanContext = Span::getCurrent()->getContext();

        if (!$spanContext->isValid()) {
            return $context;
        }

        return $context + [
            'trace_id' => $spanContext->getTraceId(),
            'span_id' => $spanContext->getSpanId(),
        ];
    }
}
