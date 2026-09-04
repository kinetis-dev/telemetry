<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Captures `$options` exactly as `TracingHttpClient::request()` builds
 * it — no normalization of its own, unlike `MockHttpClient` (which runs
 * every request through Symfony's own `HttpClientTrait::prepareRequest()`,
 * `normalizeHeaders()` included, before a callback ever sees it). A test
 * asserting against `$lastOptions` proves what this decorator itself
 * produces, independent of whether some downstream client's own
 * normalization would happen to mask a real defect in it.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public string $lastMethod = '';

    public string $lastUrl = '';

    /**
     * $failWith makes `request()` throw after recording, the shape a
     * client that validates a URL eagerly produces — and the shape
     * whose exception message quotes that URL back.
     */
    public function __construct(private readonly ?string $failWith = null) {}

    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastOptions = $options;

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        return new MockResponse('{}');
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
