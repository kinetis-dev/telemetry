<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Throwable;

/**
 * The shared span-per-query logic behind the dialect decorators. A
 * span's name is the query's own opening keyword (`SELECT`, `INSERT`),
 * per OTel's database semantic conventions, drawn from
 * {@see Redaction::sqlOperation()}'s fixed vocabulary.
 *
 * The statement itself never travels: a query built by hand carries its
 * literal values inline, and even a parameterized one describes an
 * application's schema and business rules to everyone who can read the
 * trace. What a span carries instead is the operation keyword, a
 * fingerprint that correlates every execution of the same statement,
 * and the number of parameters bound to it — see {@see Redaction} for
 * the policy this and every other decorator here follow.
 *
 * Spans are not activated: they read the current context (normally the
 * request span) as parent and end immediately, so concurrent queries
 * inside `concurrently()` never interleave anyone's scope stack.
 */
abstract class TracingSqlLinkBase implements SqlLink
{
    protected readonly TracerInterface $tracer;

    public function __construct(
        private readonly SqlLink $innerLink,
        protected readonly TracerProviderInterface $tracerProvider,
        private readonly string $dbSystem,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return $this->traced($sql, fn (): SqlResult => $this->innerLink->query($sql));
    }

    /**
     * @param list<mixed> $params
     */
    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->traced($sql, fn (): SqlResult => $this->innerLink->execute($sql, $params), count($params));
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        return $this->wrapTransaction($this->innerLink->beginTransaction());
    }

    #[\Override]
    public function close(): void
    {
        $this->innerLink->close();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->innerLink->isClosed();
    }

    /**
     * Keeps the decorated transaction on the same dialect marker as the
     * link, so query-builder dialect detection still works through it.
     */
    abstract protected function wrapTransaction(SqlTransaction $transaction): SqlTransaction;

    /**
     * $parameterCount is `null` for a statement that binds none by
     * construction — `query()`, `COMMIT`, `ROLLBACK` — which is a
     * different fact from a prepared statement that happened to bind
     * zero this time, and worth telling apart on a span.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    protected function traced(string $sql, callable $operation, ?int $parameterCount = null): mixed
    {
        $operationName = Redaction::sqlOperation($sql);
        $builder = $this->tracer->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', $this->dbSystem)
            ->setAttribute('db.operation.name', $operationName)
            ->setAttribute(
                'kinetis.db.query_fingerprint',
                Redaction::fingerprint(FingerprintDomain::SqlStatement, $sql),
            );

        if ($parameterCount !== null) {
            $builder->setAttribute('kinetis.db.parameter_count', $parameterCount);
        }

        $span = $builder->startSpan();

        try {
            return $operation();
        } catch (Throwable $e) {
            Redaction::recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
        }
    }
}
