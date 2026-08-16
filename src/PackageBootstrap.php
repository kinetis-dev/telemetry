<?php

declare(strict_types=1);

namespace Kinetis\Telemetry;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Telemetry\Instrumentation\OtelTelemetry;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;

/**
 * Registers `TracerProviderInterface` on the application container —
 * the one binding every tracing component here resolves. With no
 * `OTEL_EXPORTER_OTLP_ENDPOINT` configured, a no-op provider is bound
 * instead, so the discovered middleware and any decorators cost near
 * nothing rather than failing to resolve.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        // OTel's default context storage is Fiber-bound: a new Fiber
        // starts with no context at all, so a span begun by the request
        // middleware would be invisible inside a concurrently() task and
        // every query span there would orphan into its own trace.
        // Kinetis Fibers are scheduling units within one request, not
        // independent execution contexts, so the shared storage is the
        // correct semantics here. Only the middleware and the queue
        // decorator ever activate a scope — both strictly nested in the
        // main fiber — so the shared stack never interleaves.
        Context::setStorage(new ContextStorage());

        $provider = TracerFactory::fromConfig($config);

        if ($provider === null) {
            $app->instance(TracerProviderInterface::class, new NoopTracerProvider());

            return;
        }

        $app->instance(TracerProviderInterface::class, $provider);
        // The framework's instrumentation hooks all fire through this
        // process-wide holder; swapping the backend here is what turns
        // them into spans everywhere at once — entry points, drivers,
        // and the queue worker included, regardless of what was
        // constructed before this bootstrap ran.
        Telemetry::global()->swap(new OtelTelemetry($provider));
        register_shutdown_function($provider->shutdown(...));
    }
}
