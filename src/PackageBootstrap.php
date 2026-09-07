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
