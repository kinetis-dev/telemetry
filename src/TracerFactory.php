<?php

declare(strict_types=1);

namespace Kinetis\Telemetry;

use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
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
        )->create(rtrim($endpoint, '/') . '/v1/traces', ContentTypes::PROTOBUF);

        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => $config->string('OTEL_SERVICE_NAME', 'kinetis'),
        ])));

        return new TracerProvider(
            new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault()),
            resource: $resource,
        );
    }
}
