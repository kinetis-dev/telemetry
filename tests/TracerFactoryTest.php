<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests;

use Kinetis\Config\Config;
use Kinetis\Telemetry\TracerFactory;
use PHPUnit\Framework\TestCase;

final class TracerFactoryTest extends TestCase
{
    public function test_returns_null_when_no_endpoint_is_configured(): void
    {
        self::assertNull(TracerFactory::fromConfig(new Config([])));
    }

    public function test_builds_a_provider_carrying_the_configured_service_name(): void
    {
        $provider = TracerFactory::fromConfig(new Config([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.test:4318',
            'OTEL_SERVICE_NAME' => 'orders-api',
        ]));

        self::assertNotNull($provider);

        $resource = new \ReflectionProperty($provider, 'tracerSharedState')->getValue($provider);
        $info = new \ReflectionMethod($resource, 'getResource')->invoke($resource);

        self::assertSame('orders-api', $info->getAttributes()->get('service.name'));
    }

    public function test_the_service_name_defaults_to_kinetis(): void
    {
        $provider = TracerFactory::fromConfig(new Config([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.test:4318/',
        ]));

        self::assertNotNull($provider);

        $state = new \ReflectionProperty($provider, 'tracerSharedState')->getValue($provider);
        $info = new \ReflectionMethod($state, 'getResource')->invoke($state);

        self::assertSame('kinetis', $info->getAttributes()->get('service.name'));
    }
}
