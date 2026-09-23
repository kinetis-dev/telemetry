<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use RuntimeException;

final readonly class FailingController
{
    #[Get('/fail')]
    public function fail(): never
    {
        throw new RuntimeException('controller exploded');
    }
}
