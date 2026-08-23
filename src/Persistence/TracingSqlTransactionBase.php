<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\SqlTransaction;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * Spans `COMMIT` and `ROLLBACK` alongside the inherited per-query
 * spans — commit duration is where fsync cost shows up, which is
 * invisible from the queries alone.
 */
abstract class TracingSqlTransactionBase extends TracingSqlLinkBase implements SqlTransaction
{
    public function __construct(
        private readonly SqlTransaction $innerTransaction,
        TracerProviderInterface $tracerProvider,
        string $dbSystem,
    ) {
        parent::__construct($innerTransaction, $tracerProvider, $dbSystem);
    }

    #[\Override]
    public function commit(): void
    {
        $this->traced('COMMIT', $this->innerTransaction->commit(...));
    }

    #[\Override]
    public function rollback(): void
    {
        $this->traced('ROLLBACK', $this->innerTransaction->rollback(...));
    }

    #[\Override]
    public function isActive(): bool
    {
        return $this->innerTransaction->isActive();
    }

    /**
     * Unreachable in practice — every persistence driver rejects a
     * nested beginTransaction() before this wrapper is consulted — but
     * the base contract requires an implementation.
     */
    #[\Override]
    protected function wrapTransaction(SqlTransaction $transaction): SqlTransaction
    {
        return $transaction;
    }
}
