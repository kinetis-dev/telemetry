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
HTTP call, exported over OTLP to any tracing backend. Export goes
through [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)'s Fiber-suspending transport, so
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
  request (method, status, `php.memory.usage`; an incoming `traceparent`
  joins the caller's trace).
- **A container binding** for
  `OpenTelemetry\API\Trace\TracerProviderInterface` — the OTLP-exporting
  provider when `OTEL_EXPORTER_OTLP_ENDPOINT` is set, a no-op provider
  otherwise, so an unconfigured install costs near nothing.
- **The framework's instrumentation hooks, turned on** — when the OTLP
  endpoint is set, the bootstrap swaps an OTel backend into core's
  `Kinetis\Instrumentation\Telemetry` holder, so the spans the
  framework reports from inside itself (boot phases, per-middleware
  timing, route match, hydration, controller, queries split at the
  pool boundary, transactions, `concurrently()` tasks, events, MCP
  calls, queue jobs) start exporting with no further wiring.

Nothing else. The decorators below are explicit opt-ins wired in your
own `bootstrap.php`.

## Decorators

- `TracingMysqlLink` / `TracingPostgresLink` — a span per SQL query
  (named by its opening keyword, with a fingerprint of the statement
  and the number of parameters bound), wrapping any [`kinetis/persistence`](https://github.com/kinetis-dev/persistence) link while
  keeping its dialect marker. Transactions they begin span `COMMIT` and
  `ROLLBACK` too.
- `TracingQueue` — a producer span per `push()`; a consumer span from
  `pop()` to `ack()`/`release()`/`fail()` carrying the outcome, active
  while the job runs so its own queries and HTTP calls nest under it.
- `TracingHttpClient` — a client span per outgoing request with
  `traceparent` injection, ending when the response is consumed rather
  than when `request()` returns. Carries the URL's scheme, host and
  port. Hand it to `Http` as its transport.
- `TracingSimpleCache` — a span per cache operation, wrapping any
  PSR-16 `CacheInterface`. A key-list fingerprint and a batch size
  travel; neither the keys nor the values do.
- `TracingSessionStore` — a span per `read`/`write`/`destroy`, wrapping
  any [`kinetis/session`](https://github.com/kinetis-dev/session) `SessionStoreInterface`. The session id never
  travels verbatim (it's a bearer credential) — only its fingerprint
  does.
- `TracingOpenSearchTransport` — a span per OpenSearch call, wrapping
  the PSR-18 client via `OpenSearchClientFactory::fromConfig()`'s
  `transportDecorator` parameter.
- `TraceAwareLogger` — wraps any PSR-3 logger, adding the active span's
  `trace_id`/`span_id` to every entry's context.

## What never reaches a span

A trace is exported to a third-party backend, retained there, and
readable by everyone with access to it — a wider audience than the
database, cache, or upstream service an operation's input was addressed
to. So a span here describes an operation and never the data it
carried. Every decorator and hook routes an operation's inputs through
one internal policy point, and there is no setting that turns it off:

| Never exported | Exported instead |
|---|---|
| A SQL statement, its literal values, its bound parameters | The opening keyword from a fixed vocabulary, a fingerprint of the statement, the parameter count |
| A cache key, single or batched, and every cached value | A fingerprint of the operation's key list, and `db.operation.batch.size` for the multi-key methods |
| A URL's userinfo, path, query string, and fragment | `url.scheme`, `server.address`, `server.port`, and a fingerprint of the whole URL |
| An incoming request's path or query string | The method, and the router's own template as `http.route` on the `route.match` span |
| An OpenSearch index name, document id or alias | The action from a fixed vocabulary, and a fingerprint of the path |
| A session id, and the session payload | A fingerprint of the id |
| A failure's message and stack trace | The exception's type — an anonymous subclass reports its nearest named ancestor — as the span status and as an `exception` event's `exception.type` |

A fingerprint is a 128-bit SHA-256 prefix: two spans covering the same
statement, key list, URL or path carry the same one, so a backend still
groups them, and neither carries the value. Each digest covers the kind
of input as well as the input, so one byte sequence seen as a cache key
and as a URL fingerprints differently in each. It is pseudonymous
correlation data, not a secret — the digest is unkeyed, so a value
drawn from an enumerable set stays guessable to anyone who can hash
candidates. A failing operation's exception propagates unchanged, so an
application that wants the message logs it where its own redaction
policy applies — `TraceAwareLogger` puts the trace id on that log line,
which joins the two back together.

Span names and the attributes that say what an operation did come from
closed vocabularies for the same reason — the rule, and what each
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
