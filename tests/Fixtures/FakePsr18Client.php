<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client answering with a fixed status (200 by default) or,
 * with `$failWith` set, throwing instead — the error path.
 */
final class FakePsr18Client implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(
        private readonly int $status = 200,
        private readonly ?string $failWith = null,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        return new Response($this->status);
    }
}
