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

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

A span per request, per SQL query, per queue job, and per outgoing
HTTP call, exported over OTLP to any tracing backend. Each export
request goes through [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)'s Fiber-suspending transport;
the exporter waits between retries with a blocking sleep, which blocks
the worker for each delay.

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
  request (method, status, `php.memory.usage`; an incoming `traceparent`
  joins the caller's trace).
- **A container binding** for
  `OpenTelemetry\API\Trace\TracerProviderInterface` — the OTLP-exporting
  provider when `OTEL_EXPORTER_OTLP_ENDPOINT` is set, a no-op provider
  otherwise, so an unconfigured install costs near nothing.
- **The framework's instrumentation hooks, turned on** — when the OTLP
  endpoint is set, the bootstrap swaps an OTel backend into core's
  `Kinetis\Instrumentation\Telemetry` holder, so the spans the
  framework reports from inside itself start exporting with no further
  wiring: boot phases, per-middleware timing, route match, hydration,
  controller, `concurrently()` tasks, events, MCP calls, and — this is
  the whole of Kinetis-owned SQL and queue tracing, with nothing to
  wrap by hand — a span per query split at the pool boundary, a
  transaction span carrying its outcome, and producer and consumer job
  spans joined into one trace across processes by a `traceparent` the
  push hook stores with the job.

The decorators below are explicit opt-ins wired in your own
`bootstrap.php`, for the boundaries the framework reports nothing from.

## Scope ownership across Fibers

An active span's scope belongs to the Fiber that started it —
OpenTelemetry's default Fiber-bound context storage stays in place — so
two overlapping `concurrently()` tasks each keep their own stack and
neither can detach the other's span. Parentage across a Fiber boundary
is passed rather than read: the batch hook's token reaches each task
hook and parents the task span to its batch, and a span starting on a
Fiber that carries no context (a request span, a worker's job span)
names its parent explicitly — the propagated `traceparent`, or the
trace root.

## Decorators

- `TracingHttpClient` — a client span per outgoing request with
  `traceparent` injection, ending when the response is consumed rather
  than when `request()` returns. Carries the URL's scheme, host and
  port. Hand it to `Http` as its transport.
- `TracingSimpleCache` — a span per cache operation, wrapping any
  PSR-16 `CacheInterface`. A key-list fingerprint and a batch size
  travel; neither the keys nor the values do.
- `TracingSessionStore` — a span per `read`/`create`/`update`/`destroy`, wrapping
  any [`kinetis/session`](https://github.com/kinetis-dev/session) `SessionStoreInterface`. The session id never
  travels verbatim (it's a bearer credential) — only its fingerprint
  does.
- `TracingSearchTransport` — a span per search call on either engine,
  wrapping the PSR-18 client via each engine factory's
  `transportDecorator` parameter.
- `TraceAwareLogger` — wraps any PSR-3 logger, adding the active span's
  `trace_id`/`span_id` to every entry's context.

## What never reaches a span

A trace is exported to a third-party backend, retained there, and
readable by everyone with access to it — a wider audience than the
database, cache, or upstream service an operation's input was addressed
to. So a span here describes an operation and never the data it
carried. Every decorator and hook routes an operation's inputs through
one internal policy point, and there is no setting that turns it off.

A SQL statement and its parameters, a cache key and its value, a URL's
userinfo/path/query/fragment, an incoming request's path, a search
index name or document id, a session id and its payload, and a
failure's message and stack trace all stay behind. What travels in
their place is an unkeyed 128-bit SHA-256 fingerprint — enough for a
backend to group two spans over the same value, never the value — plus
the operation's own name drawn from a closed vocabulary. A failing
operation's exception propagates unchanged, so an application that
wants the message logs it where its own redaction policy applies, and
`TraceAwareLogger` puts the trace id on that log line.

The full table of what is dropped and what replaces it, and what each
vocabulary falls back to, is stated once at
[kinetis.dev/docs/telemetry.html](https://kinetis.dev/docs/telemetry.html#what-never-reaches-a-span).

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | Collector's OTLP/HTTP base URL. Unset = tracing off (no-op provider). |
| `OTEL_SERVICE_NAME` | `kinetis` | The `service.name` resource attribute. |
| `OTEL_EXPORTER_OTLP_HEADERS` | — | Export headers, `key=value,key2=value2` — a hosted backend's auth. |
| `OTEL_TRACES_SAMPLER` | `parentbased_always_on` | Standard sampler names; `traceidratio` + `OTEL_TRACES_SAMPLER_ARG` for a rate. |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` | Ratio for the `traceidratio` samplers, `0`–`1`. |

Export never follows a redirect: the exporter retries a redirect
response against the configured endpoint up to its retry limit, then
reports an export failure — see
[kinetis.dev/docs/telemetry.html](https://kinetis.dev/docs/telemetry.html#configuration).

## Installation

```sh
composer require kinetis/telemetry
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework),
and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
Full documentation:
[kinetis.dev/docs/telemetry.html](https://kinetis.dev/docs/telemetry.html).

## License

MIT — see [LICENSE](LICENSE).
