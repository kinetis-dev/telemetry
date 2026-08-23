<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Persistence;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Throwable;

/**
 * The shared span-per-query logic behind the dialect decorators. A
 * span's name is the query's own first keyword (`SELECT`, `INSERT`),
 * per OTel's database semantic conventions; the full SQL travels as
 * `db.query.text`. Bound parameter values are deliberately never
 * recorded — they are exactly the data most likely to be sensitive.
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
        return $this->traced($sql, fn (): SqlResult => $this->innerLink->execute($sql, $params));
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
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    protected function traced(string $sql, callable $operation): mixed
    {
        $span = $this->tracer->spanBuilder($this->operationName($sql))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', $this->dbSystem)
            ->setAttribute('db.query.text', $sql)
            ->startSpan();

        try {
            return $operation();
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @return non-empty-string
     */
    private function operationName(string $sql): string
    {
        $keyword = strtok(ltrim($sql), " \t\n\r(");

        return $keyword === false ? 'SQL' : strtoupper($keyword);
    }
}
