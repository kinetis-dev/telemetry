<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Session;

use Kinetis\Session\SessionStoreInterface;
use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Throwable;

/**
 * A span per session-store call, wrapping any `SessionStoreInterface` —
 * `kinetis/session`'s file/cache/SQL stores included. `SESSION_DRIVER`'s
 * own binding is a lazy factory resolved on first use, so re-binding in
 * `bootstrap.php` replaces it cleanly — the same pattern the session
 * package's own docs use for a custom store:
 *
 *     $app->bind(SessionStoreInterface::class, static fn (): TracingSessionStore
 *         => new TracingSessionStore(
 *             new FileSessionStore($config->string('SESSION_FILES_DIR', ...)),
 *             $app->get(TracerProviderInterface::class),
 *         ));
 *
 * A session id is a bearer credential — whoever holds it can present
 * the cookie and act as that session — so it never travels to a span
 * verbatim, the same reasoning `kinetis/auth`'s `TokenGenerator`/
 * `RevocationStore` never store a raw token either. What goes on the
 * span is the {@see Redaction::fingerprint()} of it: enough to
 * correlate every span for one session without handing an APM reader
 * the credential itself. The payload never travels at all, under the
 * same policy that keeps SQL statements and cache keys off a span.
 *
 * Spans are not activated: they read the current context (normally the
 * request span) as parent and end immediately.
 */
final class TracingSessionStore implements SessionStoreInterface
{
    private readonly TracerInterface $tracer;

    public function __construct(
        private readonly SessionStoreInterface $inner,
        TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('kinetis');
    }

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        return $this->traced('read', $id, fn (): ?array => $this->inner->read($id));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->traced('write', $id, function () use ($id, $data, $lifetimeSeconds): void {
            $this->inner->write($id, $data, $lifetimeSeconds);
        });
    }

    #[\Override]
    public function destroy(string $id): void
    {
        $this->traced('destroy', $id, function () use ($id): void {
            $this->inner->destroy($id);
        });
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    private function traced(string $operation, string $id, callable $run): mixed
    {
        $span = $this->tracer->spanBuilder("session.{$operation}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('kinetis.session.operation', $operation)
            ->setAttribute('kinetis.session.id_fingerprint', Redaction::fingerprint(FingerprintDomain::SessionId, $id))
            ->startSpan();

        try {
            return $run();
        } catch (Throwable $e) {
            Redaction::recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
        }
    }
}
