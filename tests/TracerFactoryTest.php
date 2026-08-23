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

    public function test_otlp_headers_are_parsed_and_reach_the_transport(): void
    {
        $headers = TracerFactory::headersFromConfig(new Config([
            'OTEL_EXPORTER_OTLP_HEADERS' => 'x-honeycomb-team=secret-key, x-dataset=prod',
        ]));

        self::assertSame(['x-honeycomb-team' => 'secret-key', 'x-dataset' => 'prod'], $headers);

        $provider = TracerFactory::fromConfig(new Config([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.test:4318',
            'OTEL_EXPORTER_OTLP_HEADERS' => 'x-honeycomb-team=secret-key',
        ]));
        self::assertNotNull($provider);

        // Down the real chain: provider -> shared state -> processor ->
        // exporter -> transport, whose headers are what actually go out.
        $state = new \ReflectionProperty($provider, 'tracerSharedState')->getValue($provider);
        $processor = new \ReflectionMethod($state, 'getSpanProcessor')->invoke($state);
        $exporter = new \ReflectionProperty($processor, 'exporter')->getValue($processor);
        $transport = new \ReflectionProperty($exporter, 'transport')->getValue($exporter);
        $sent = new \ReflectionProperty($transport, 'headers')->getValue($transport);

        self::assertSame('secret-key', $sent['x-honeycomb-team'] ?? null);
    }

    public function test_no_headers_configured_means_none_added(): void
    {
        self::assertSame([], TracerFactory::headersFromConfig(new Config([])));
    }

    public function test_the_sampler_defaults_to_parent_based_always_on(): void
    {
        self::assertInstanceOf(
            \OpenTelemetry\SDK\Trace\Sampler\ParentBased::class,
            TracerFactory::samplerFromConfig(new Config([])),
        );
    }

    public function test_each_standard_sampler_name_builds_its_sampler(): void
    {
        self::assertInstanceOf(
            \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler::class,
            TracerFactory::samplerFromConfig(new Config(['OTEL_TRACES_SAMPLER' => 'always_on'])),
        );
        self::assertInstanceOf(
            \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler::class,
            TracerFactory::samplerFromConfig(new Config(['OTEL_TRACES_SAMPLER' => 'always_off'])),
        );
        self::assertInstanceOf(
            \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler::class,
            TracerFactory::samplerFromConfig(new Config([
                'OTEL_TRACES_SAMPLER' => 'traceidratio',
                'OTEL_TRACES_SAMPLER_ARG' => '0.1',
            ])),
        );
        self::assertInstanceOf(
            \OpenTelemetry\SDK\Trace\Sampler\ParentBased::class,
            TracerFactory::samplerFromConfig(new Config(['OTEL_TRACES_SAMPLER' => 'parentbased_traceidratio'])),
        );
    }

    public function test_a_ratio_sampler_actually_samples_at_the_configured_rate(): void
    {
        $sampler = TracerFactory::samplerFromConfig(new Config([
            'OTEL_TRACES_SAMPLER' => 'traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '0',
        ]));

        // Ratio 0 must drop everything — provable without statistics.
        $result = $sampler->shouldSample(
            \OpenTelemetry\Context\Context::getRoot(),
            'aaaabbbbccccddddaaaabbbbccccdddd',
            'op',
            \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([]),
            [],
        );

        self::assertSame(\OpenTelemetry\SDK\Trace\SamplingResult::DROP, $result->getDecision());
    }

    public function test_an_unknown_sampler_name_throws_naming_the_valid_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown OTEL_TRACES_SAMPLER "xor"');

        TracerFactory::samplerFromConfig(new Config(['OTEL_TRACES_SAMPLER' => 'xor']));
    }

    public function test_an_out_of_range_ratio_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be between 0 and 1');

        TracerFactory::samplerFromConfig(new Config([
            'OTEL_TRACES_SAMPLER' => 'traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '1.5',
        ]));
    }
}
