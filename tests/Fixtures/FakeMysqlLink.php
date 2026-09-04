<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\QueryException;

/**
 * Records every call and returns empty results; `$failWith` makes every
 * query throw instead, for the error paths.
 */
final class FakeMysqlLink implements MysqlLink
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $calls = [];

    public bool $committed = false;

    public bool $rolledBack = false;

    private bool $closed = false;

    public function __construct(private readonly ?string $failWith = null) {}

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return $this->run($sql, []);
    }

    /**
     * @param list<mixed> $params
     */
    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->run($sql, $params);
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        return new class($this) implements MysqlTransaction {
            private bool $active = true;

            public function __construct(private readonly FakeMysqlLink $link) {}

            #[\Override]
            public function query(string $sql): SqlResult
            {
                return $this->link->query($sql);
            }

            /**
             * @param list<mixed> $params
             */
            #[\Override]
            public function execute(string $sql, array $params = []): SqlResult
            {
                return $this->link->execute($sql, $params);
            }

            #[\Override]
            public function beginTransaction(): SqlTransaction
            {
                throw new QueryException('Nested transactions are not supported.');
            }

            #[\Override]
            public function commit(): void
            {
                $this->active = false;
                $this->link->committed = true;
            }

            #[\Override]
            public function rollback(): void
            {
                $this->active = false;
                $this->link->rolledBack = true;
            }

            #[\Override]
            public function isActive(): bool
            {
                return $this->active;
            }

            #[\Override]
            public function close(): void
            {
                $this->active = false;
            }

            #[\Override]
            public function isClosed(): bool
            {
                return !$this->active;
            }
        };
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): SqlResult
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failWith !== null) {
            throw new QueryException($this->failWith, $sql);
        }

        return new FakeSqlResult();
    }
}
