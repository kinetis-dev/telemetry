<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * A span per query, wrapping any MySQL link. Register it around
 * whatever `bootstrap.php` already builds:
 *
 *     $app->instance(MysqlLink::class, new TracingMysqlLink(
 *         SqlConnectionFactory::fromConfig($config),
 *         $app->get(TracerProviderInterface::class),
 *     ));
 *
 * Implements the MysqlLink marker itself, so query-builder dialect
 * detection sees the decorated link exactly like the real one.
 */
final class TracingMysqlLink extends TracingSqlLinkBase implements MysqlLink
{
    public function __construct(MysqlLink $inner, TracerProviderInterface $tracerProvider)
    {
        parent::__construct($inner, $tracerProvider, 'mysql');
    }

    #[\Override]
    protected function wrapTransaction(SqlTransaction $transaction): SqlTransaction
    {
        // The contract's beginTransaction() loses the dialect a real
        // MySQL link always returns; narrowed back here.
        if (!$transaction instanceof MysqlTransaction) {
            throw new LogicException('A MysqlLink must hand back a MysqlTransaction; got ' . $transaction::class . '.');
        }

        return new TracingMysqlTransaction($transaction, $this->tracerProvider);
    }
}
