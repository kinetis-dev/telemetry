<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Persistence;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Telemetry\Persistence\TracingMysqlLink;
use Kinetis\Telemetry\Persistence\TracingMysqlTransaction;
use Kinetis\Telemetry\Persistence\TracingPostgresLink;
use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use Kinetis\Telemetry\Tests\Fixtures\FakeMysqlLink;
use Kinetis\Telemetry\Tests\Fixtures\FakePostgresLink;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

final class TracingLinkTest extends TracingTestCase
{
    public function test_a_query_produces_a_client_span_named_by_its_opening_keyword(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->query('SELECT * FROM orders');

        $span = $this->span();
        self::assertSame('SELECT', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('mysql', $span->getAttributes()->get('db.system.name'));
        self::assertSame('SELECT', $span->getAttributes()->get('db.operation.name'));
        self::assertSame(
            Redaction::fingerprint(FingerprintDomain::SqlStatement, 'SELECT * FROM orders'),
            $span->getAttributes()->get('kinetis.db.query_fingerprint'),
        );
    }

    /**
     * Two runs of one statement share a fingerprint and a different
     * statement gets a different one — what makes the attribute worth
     * exporting, since grouping a backend's spans by statement is the
     * diagnostic the raw text would otherwise have provided.
     */
    public function test_the_query_fingerprint_groups_identical_statements_and_separates_different_ones(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->query('SELECT * FROM orders');
        $link->query('SELECT * FROM orders');
        $link->query('SELECT * FROM users');

        [$first, $second, $third] = $this->spans();
        self::assertSame(
            $first->getAttributes()->get('kinetis.db.query_fingerprint'),
            $second->getAttributes()->get('kinetis.db.query_fingerprint'),
        );
        self::assertNotSame(
            $first->getAttributes()->get('kinetis.db.query_fingerprint'),
            $third->getAttributes()->get('kinetis.db.query_fingerprint'),
        );
    }

    /**
     * A span name comes from a fixed keyword vocabulary, so a statement
     * opening with anything else — including text a caller built — is
     * named `SQL` rather than putting that first word on the span.
     */
    public function test_a_statement_outside_the_keyword_vocabulary_is_named_sql(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->query("PLEASE-RUN 'hunter2'");

        $span = $this->span();
        self::assertSame('SQL', $span->getName());
        self::assertSame('SQL', $span->getAttributes()->get('db.operation.name'));
    }

    public function test_execute_records_how_many_parameters_it_bound_and_none_of_their_values(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->execute('INSERT INTO users (email) VALUES (?)', ['secret@example.test']);

        $span = $this->span();
        self::assertSame('INSERT', $span->getName());
        self::assertSame(1, $span->getAttributes()->get('kinetis.db.parameter_count'));
        self::assertStringNotContainsString(
            'secret@example.test',
            var_export($span->getAttributes()->toArray(), true),
        );
    }

    /**
     * `query()` binds no parameters by construction, which is a
     * different fact from a prepared statement that bound zero — the
     * attribute is absent rather than `0`.
     */
    public function test_query_carries_no_parameter_count_at_all(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->query('SELECT 1');

        self::assertNull($this->span()->getAttributes()->get('kinetis.db.parameter_count'));
    }

    public function test_a_failing_query_marks_the_span_as_an_error_and_rethrows(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(failWith: 'no such table'), $this->tracerProvider);

        try {
            $link->query('SELECT * FROM missing');
            self::fail('Expected the query exception to propagate.');
        } catch (QueryException) {
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_the_decorated_link_keeps_its_dialect_marker(): void
    {
        self::assertInstanceOf(
            MysqlLink::class,
            new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider),
        );
        self::assertInstanceOf(
            PostgresLink::class,
            new TracingPostgresLink(new FakePostgresLink(), $this->tracerProvider),
        );
    }

    public function test_a_transaction_is_wrapped_and_its_commit_gets_a_span(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $transaction = $link->beginTransaction();
        self::assertInstanceOf(TracingMysqlTransaction::class, $transaction);

        $transaction->execute('UPDATE orders SET total = ?', [10]);
        $transaction->commit();

        [$update, $commit] = $this->spans();
        self::assertSame('UPDATE', $update->getName());
        self::assertSame('COMMIT', $commit->getName());
    }

    public function test_rollback_gets_a_span_too(): void
    {
        $transaction = new TracingPostgresLink(new FakePostgresLink(), $this->tracerProvider)->beginTransaction();

        $transaction->rollback();

        $span = $this->span();
        self::assertSame('ROLLBACK', $span->getName());
        self::assertSame('postgresql', $span->getAttributes()->get('db.system.name'));
    }

    public function test_close_and_is_closed_delegate_without_spans(): void
    {
        $inner = new FakeMysqlLink();
        $link = new TracingMysqlLink($inner, $this->tracerProvider);

        self::assertFalse($link->isClosed());
        $link->close();
        self::assertTrue($link->isClosed());
        self::assertSame([], $this->spans());
    }
}
