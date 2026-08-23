<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\MysqlTransaction;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/** What {@see TracingMysqlLink}'s beginTransaction() hands back. */
final class TracingMysqlTransaction extends TracingSqlTransactionBase implements MysqlTransaction
{
    public function __construct(MysqlTransaction $inner, TracerProviderInterface $tracerProvider)
    {
        parent::__construct($inner, $tracerProvider, 'mysql');
    }
}
