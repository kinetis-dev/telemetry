<?php

declare(strict_types=1);

namespace Kinetis\Telemetry;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Parser\MapParser;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the OTLP-exporting tracer provider from configuration.
 *
 * Export goes over kinetis/revolt-http-client's Fiber-suspending
 * transport, so flushing a span batch never blocks the worker the way a
 * curl-based exporter would. Spans batch in memory and export when the
 * batch fills or on shutdown — `register_shutdown_function` runs at
 * request end under boot-and-die runtimes and at worker exit under a
 * persistent one, so both shapes flush without configuration.
 */
final class TracerFactory
{
    /**
     * Null when `OTEL_EXPORTER_OTLP_ENDPOINT` isn't set — telemetry is
     * opt-in per environment, not merely per install.
     */
    public static function fromConfig(Config $config): ?TracerProvider
    {
        $endpoint = $config->string('OTEL_EXPORTER_OTLP_ENDPOINT', '');

        if ($endpoint === '') {
            return null;
        }

        $psr17 = new Psr17Factory();
        $transport = new PsrTransportFactory(
            new Psr18Client(AmpHttpClientFactory::create()),
            $psr17,
            $psr17,
        )->create(
            rtrim($endpoint, '/') . '/v1/traces',
            ContentTypes::PROTOBUF,
            self::headersFromConfig($config),
        );

        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => $config->string('OTEL_SERVICE_NAME', 'kinetis'),
        ])));

        return new TracerProvider(
            new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault()),
            self::samplerFromConfig($config),
            resource: $resource,
        );
    }

    /**
     * `OTEL_EXPORTER_OTLP_HEADERS`, the standard `key=value,key2=value2`
     * format — what a hosted backend's auth rides on (an API-key header
     * for Honeycomb, Grafana Cloud, or any authenticated collector).
     *
     * @return array<string, string>
     */
    public static function headersFromConfig(Config $config): array
    {
        /** @var array<string, string> */
        return MapParser::parse($config->string('OTEL_EXPORTER_OTLP_HEADERS', ''));
    }

    /**
     * `OTEL_TRACES_SAMPLER` (+ `OTEL_TRACES_SAMPLER_ARG` for the ratio
     * samplers), the standard names. Unset keeps the spec's own default,
     * `parentbased_always_on` — every span sampled, which is also what
     * this provider did before sampling was configurable. An
     * unrecognized name throws naming the valid set, never a silent
     * fallback to a sampler that wasn't asked for.
     */
    public static function samplerFromConfig(Config $config): SamplerInterface
    {
        $name = $config->string('OTEL_TRACES_SAMPLER', 'parentbased_always_on');

        return match ($name) {
            'always_on' => new AlwaysOnSampler(),
            'always_off' => new AlwaysOffSampler(),
            'traceidratio' => new TraceIdRatioBasedSampler(self::ratioArg($config)),
            'parentbased_always_on' => new ParentBased(new AlwaysOnSampler()),
            'parentbased_always_off' => new ParentBased(new AlwaysOffSampler()),
            'parentbased_traceidratio' => new ParentBased(new TraceIdRatioBasedSampler(self::ratioArg($config))),
            default => throw new InvalidArgumentException(
                "Unknown OTEL_TRACES_SAMPLER \"{$name}\" — valid values: always_on, always_off, traceidratio, "
                . 'parentbased_always_on, parentbased_always_off, parentbased_traceidratio.',
            ),
        };
    }

    private static function ratioArg(Config $config): float
    {
        $ratio = $config->float('OTEL_TRACES_SAMPLER_ARG', 1.0);

        if ($ratio < 0.0 || $ratio > 1.0) {
            throw new InvalidArgumentException(
                "OTEL_TRACES_SAMPLER_ARG must be between 0 and 1, got {$ratio}.",
            );
        }

        return $ratio;
    }
}
