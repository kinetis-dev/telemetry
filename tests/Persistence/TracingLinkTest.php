<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Persistence;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Telemetry\Persistence\TracingMysqlLink;
use Kinetis\Telemetry\Persistence\TracingMysqlTransaction;
use Kinetis\Telemetry\Persistence\TracingPostgresLink;
use Kinetis\Telemetry\Tests\Fixtures\FakeMysqlLink;
use Kinetis\Telemetry\Tests\Fixtures\FakePostgresLink;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

final class TracingLinkTest extends TracingTestCase
{
    public function test_a_query_produces_a_client_span_named_by_its_first_keyword(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->query('SELECT * FROM orders');

        $span = $this->span();
        self::assertSame('SELECT', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('mysql', $span->getAttributes()->get('db.system.name'));
        self::assertSame('SELECT * FROM orders', $span->getAttributes()->get('db.query.text'));
    }

    public function test_execute_is_spanned_but_parameter_values_are_never_recorded(): void
    {
        $link = new TracingMysqlLink(new FakeMysqlLink(), $this->tracerProvider);

        $link->execute('INSERT INTO users (email) VALUES (?)', ['secret@example.test']);

        $span = $this->span();
        self::assertSame('INSERT', $span->getName());
        self::assertStringNotContainsString(
            'secret@example.test',
            var_export($span->getAttributes()->toArray(), true),
        );
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
