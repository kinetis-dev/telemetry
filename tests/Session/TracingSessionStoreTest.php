<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests\Session;

use Kinetis\Telemetry\Session\TracingSessionStore;
use Kinetis\Telemetry\Tests\Fixtures\FakeSessionStore;
use Kinetis\Telemetry\Tests\TracingTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

final class TracingSessionStoreTest extends TracingTestCase
{
    public function test_read_produces_a_client_span_naming_the_operation(): void
    {
        $store = new TracingSessionStore(new FakeSessionStore(), $this->tracerProvider);

        $store->read('a-real-session-id');

        $span = $this->span();
        self::assertSame('session.read', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame('read', $span->getAttributes()->get('kinetis.session.operation'));
    }

    public function test_the_session_id_never_travels_on_the_span_verbatim(): void
    {
        $store = new TracingSessionStore(new FakeSessionStore(), $this->tracerProvider);

        $store->read('a-real-session-id');

        self::assertStringNotContainsString(
            'a-real-session-id',
            var_export($this->span()->getAttributes()->toArray(), true),
        );
    }

    public function test_two_calls_for_the_same_session_id_carry_the_same_fingerprint(): void
    {
        $store = new TracingSessionStore(new FakeSessionStore(), $this->tracerProvider);

        $store->read('session-a');
        $store->destroy('session-a');

        $first = $this->span(0)->getAttributes()->get('kinetis.session.id_fingerprint');
        $second = $this->span(1)->getAttributes()->get('kinetis.session.id_fingerprint');

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    public function test_write_is_spanned_but_the_payload_is_never_recorded(): void
    {
        $store = new TracingSessionStore(new FakeSessionStore(), $this->tracerProvider);

        $store->write('session-a', ['auth_user_id' => 'secret-user-42'], 3600);

        $span = $this->span();
        self::assertSame('session.write', $span->getName());
        self::assertStringNotContainsString(
            'secret-user-42',
            var_export($span->getAttributes()->toArray(), true),
        );
    }

    public function test_a_failing_operation_marks_the_span_as_an_error_and_rethrows(): void
    {
        $store = new TracingSessionStore(new FakeSessionStore(failWith: 'disk full'), $this->tracerProvider);

        try {
            $store->write('session-a', [], 3600);
            self::fail('Expected the store exception to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    public function test_read_returns_the_inner_stores_result_unmodified(): void
    {
        $inner = new FakeSessionStore();
        $inner->write('session-a', ['flag' => true], 3600);

        $store = new TracingSessionStore($inner, $this->tracerProvider);

        self::assertSame(['flag' => true], $store->read('session-a'));
    }
}
