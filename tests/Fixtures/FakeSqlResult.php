<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use ArrayIterator;
use IteratorAggregate;
use Kinetis\Persistence\Contract\SqlResult;
use Traversable;

/**
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class FakeSqlResult implements SqlResult, IteratorAggregate
{
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function fetchRow(): ?array
    {
        return null;
    }

    #[\Override]
    public function getRowCount(): ?int
    {
        return 0;
    }

    #[\Override]
    public function getColumnCount(): ?int
    {
        return 0;
    }

    #[\Override]
    public function getLastInsertId(): ?int
    {
        return null;
    }
}
