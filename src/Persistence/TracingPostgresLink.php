<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * A span per query, wrapping any Postgres link — the Postgres side of
 * {@see TracingMysqlLink}, carrying the PostgresLink marker so dialect
 * detection sees the decorated link exactly like the real one.
 */
final class TracingPostgresLink extends TracingSqlLinkBase implements PostgresLink
{
    public function __construct(PostgresLink $inner, TracerProviderInterface $tracerProvider)
    {
        parent::__construct($inner, $tracerProvider, 'postgresql');
    }

    #[\Override]
    protected function wrapTransaction(SqlTransaction $transaction): SqlTransaction
    {
        // The contract's beginTransaction() loses the dialect a real
        // Postgres link always returns; narrowed back here.
        if (!$transaction instanceof PostgresTransaction) {
            throw new LogicException('A PostgresLink must hand back a PostgresTransaction; got ' . $transaction::class . '.');
        }

        return new TracingPostgresTransaction($transaction, $this->tracerProvider);
    }
}
