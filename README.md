<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/telemetry</strong>
  <br>
  <strong>OpenTelemetry tracing for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/telemetry"><img src="https://img.shields.io/packagist/v/kinetis/telemetry?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/telemetry"><img src="https://img.shields.io/packagist/dt/kinetis/telemetry" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/telemetry"><img src="https://img.shields.io/packagist/php-v/kinetis/telemetry" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/telemetry"><img src="https://img.shields.io/packagist/l/kinetis/telemetry" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

A span per request, per SQL query, per queue job, and per outgoing
HTTP call, exported over OTLP to any tracing backend. Export goes
through `kinetis/revolt-http-client`'s Fiber-suspending transport, so
flushing a span batch never blocks the worker.

The distinctive trace this produces: spans that *overlap in time*. A
request running two queries and an HTTP call through `concurrently()`
shows all three side by side inside the request span — what
non-blocking I/O actually did for that request, visible.

Set one environment variable and requests start tracing:

```sh
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318
```

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **Global middleware** `RequestSpanMiddleware` — one server span per
  request (method, `url.path`, status, `php.memory.usage`; an incoming
  `traceparent` joins the caller's trace).
- **A container binding** for
  `OpenTelemetry\API\Trace\TracerProviderInterface` — the OTLP-exporting
  provider when `OTEL_EXPORTER_OTLP_ENDPOINT` is set, a no-op provider
  otherwise, so an unconfigured install costs near nothing.

Nothing else. The decorators below are explicit opt-ins wired in your
own `bootstrap.php`.

## Decorators

- `TracingMysqlLink` / `TracingPostgresLink` — a span per SQL query
  (named by first keyword, full SQL as `db.query.text`, parameter
  values never recorded), wrapping any `kinetis/persistence` link while
  keeping its dialect marker. Transactions they begin span `COMMIT` and
  `ROLLBACK` too.
- `TracingQueue` — a producer span per `push()`; a consumer span from
  `pop()` to `ack()`/`release()`/`fail()` carrying the outcome, active
  while the job runs so its own queries and HTTP calls nest under it.
- `TracingHttpClient` — a client span per outgoing request with
  `traceparent` injection, ending when the response is consumed rather
  than when `request()` returns. Hand it to `Http` as its transport.
- `TraceAwareLogger` — wraps any PSR-3 logger, adding the active span's
  `trace_id`/`span_id` to every entry's context.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | Collector's OTLP/HTTP base URL. Unset = tracing off (no-op provider). |
| `OTEL_SERVICE_NAME` | `kinetis` | The `service.name` resource attribute. |

## Installation

```sh
composer require kinetis/telemetry
```

Requires PHP 8.4+. Full documentation:
[docs.kinetis.dev/telemetry.html](https://docs.kinetis.dev/telemetry.html).

## License

MIT — see [LICENSE](../../LICENSE).
