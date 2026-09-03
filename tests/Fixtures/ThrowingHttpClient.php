<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An `HttpClientInterface` whose `request()` throws synchronously,
 * immediately — the shape a real client validating options/URLs
 * eagerly (or a decorator failing before ever reaching a transport) can
 * genuinely produce, distinct from `MockHttpClient`'s own
 * always-defer-the-error-to-response-consumption behavior.
 */
final class ThrowingHttpClient implements HttpClientInterface
{
    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        throw new RuntimeException('synchronous transport failure');
    }

    #[\Override]
    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new RuntimeException('not implemented');
    }

    #[\Override]
    public function withOptions(array $options): static
    {
        return $this;
    }
}
