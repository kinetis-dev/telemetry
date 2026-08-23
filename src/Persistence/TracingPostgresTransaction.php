<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\PostgresTransaction;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/** What {@see TracingPostgresLink}'s beginTransaction() hands back. */
final class TracingPostgresTransaction extends TracingSqlTransactionBase implements PostgresTransaction
{
    public function __construct(PostgresTransaction $inner, TracerProviderInterface $tracerProvider)
    {
        parent::__construct($inner, $tracerProvider, 'postgresql');
    }
}
