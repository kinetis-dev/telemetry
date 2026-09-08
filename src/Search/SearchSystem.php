<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Search;

/**
 * The search engine a {@see TracingSearchTransport} span reports as
 * `db.system.name`, from OpenTelemetry's own registry of values.
 *
 * A closed set rather than a caller's string, for the reason every other
 * exported vocabulary in {@see \Kinetis\Telemetry\Redaction} is closed:
 * an attribute value reaches a collector, and only values chosen here
 * can.
 */
enum SearchSystem: string
{
    case OpenSearch = 'opensearch';

    case Elasticsearch = 'elasticsearch';
}
