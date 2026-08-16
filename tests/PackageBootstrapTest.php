<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Telemetry\Instrumentation\OtelTelemetry;
use Kinetis\Telemetry\PackageBootstrap;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class PackageBootstrapTest extends TestCase
{
    public function test_without_an_endpoint_a_noop_provider_is_bound(): void
    {
        $app = new AppScope();

        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        self::assertInstanceOf(NoopTracerProvider::class, $app->get(TracerProviderInterface::class));
    }

    public function test_with_an_endpoint_a_real_exporting_provider_is_bound(): void
    {
        $app = new AppScope();

        new PackageBootstrap()->register($app, new Config([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.test:4318',
        ]));
        $app->boot();

        self::assertInstanceOf(TracerProvider::class, $app->get(TracerProviderInterface::class));
    }

    public function test_the_shared_context_storage_replaces_the_fiber_bound_default(): void
    {
        new PackageBootstrap()->register(new AppScope(), new Config([]));

        self::assertInstanceOf(ContextStorage::class, Context::storage());
    }

    public function test_with_an_endpoint_the_global_holder_gets_the_otel_backend(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
        $app = new AppScope();

        new PackageBootstrap()->register($app, new Config([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.test:4318',
        ]));

        $backend = new \ReflectionProperty(Telemetry::class, 'backend')->getValue(Telemetry::global());
        self::assertInstanceOf(OtelTelemetry::class, $backend);
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_without_an_endpoint_the_global_holder_keeps_its_backend(): void
    {
        Telemetry::global()->swap(new NullTelemetry());

        new PackageBootstrap()->register(new AppScope(), new Config([]));

        $backend = new \ReflectionProperty(Telemetry::class, 'backend')->getValue(Telemetry::global());
        self::assertInstanceOf(NullTelemetry::class, $backend);
    }

    public function test_the_binding_reaches_a_request_scope(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        $provider = $app->createRequestScope()->get(TracerProviderInterface::class);

        self::assertSame($app->get(TracerProviderInterface::class), $provider);
    }
}
