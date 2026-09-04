<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\HttpClient;

use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Ends {@see TracingHttpClient}'s span when the response is actually
 * consumed — a full body read (`getContent()`/`toArray()`), an error,
 * a `cancel()`, or, as the safety net, this wrapper being discarded
 * unread. `getStatusCode()`/`getHeaders()` only wait for headers, so
 * they record the status onto the span without ending it: the body may
 * still be in flight.
 */
final class TracingResponse implements ResponseInterface
{
    private bool $ended = false;

    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly SpanInterface $span,
    ) {}

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->guarded(function (): int {
            $status = $this->inner->getStatusCode();
            $this->recordStatus($status);

            return $status;
        });
    }

    /**
     * @return array<string, list<string>>
     */
    #[\Override]
    public function getHeaders(bool $throw = true): array
    {
        return $this->guarded(function () use ($throw): array {
            $headers = $this->inner->getHeaders($throw);
            $this->recordStatus($this->inner->getStatusCode());

            return $headers;
        });
    }

    #[\Override]
    public function getContent(bool $throw = true): string
    {
        return $this->guarded(function () use ($throw): string {
            $content = $this->inner->getContent($throw);
            $this->finish();

            return $content;
        });
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function toArray(bool $throw = true): array
    {
        return $this->guarded(function () use ($throw): array {
            $data = $this->inner->toArray($throw);
            $this->finish();

            return $data;
        });
    }

    #[\Override]
    public function cancel(): void
    {
        $this->inner->cancel();
        $this->span->setAttribute('http.request.cancelled', true);
        $this->finish();
    }

    #[\Override]
    public function getInfo(?string $type = null): mixed
    {
        return $this->inner->getInfo($type);
    }

    /** The real response, for {@see TracingHttpClient::stream()}. */
    public function unwrap(): ResponseInterface
    {
        return $this->inner;
    }

    public function __destruct()
    {
        $this->finish();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            // A transport exception names the URL it failed to reach,
            // so only its class reaches the span — the same rule
            // TracingHttpClient's own synchronous catch follows.
            Redaction::recordFailure($this->span, $e);
            $this->finish();

            throw $e;
        }
    }

    private function recordStatus(int $status): void
    {
        $this->span->setAttribute('http.response.status_code', $status);

        if ($status >= 400) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    private function finish(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;

        // getInfo() never throws, so this stays safe from __destruct.
        $status = $this->inner->getInfo('http_code');

        if (is_int($status) && $status > 0) {
            $this->recordStatus($status);
        }

        $this->span->end();
    }
}
