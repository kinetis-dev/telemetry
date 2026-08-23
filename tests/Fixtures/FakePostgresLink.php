<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\QueryException;

/** The Postgres-marked sibling of {@see FakeMysqlLink}. */
final class FakePostgresLink implements PostgresLink
{
    private bool $closed = false;

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return new FakeSqlResult();
    }

    /**
     * @param list<mixed> $params
     */
    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return new FakeSqlResult();
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        return new class($this) implements PostgresTransaction {
            private bool $active = true;

            public function __construct(private readonly FakePostgresLink $link) {}

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
            }

            #[\Override]
            public function rollback(): void
            {
                $this->active = false;
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
}
