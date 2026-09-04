<?php

declare(strict_types=1);

namespace Kinetis\Telemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * The one place that decides what an operation's own inputs may
 * contribute to a span. {@link https://kinetis.dev/docs/telemetry.html}'s
 * "What never reaches a span" is the reader-facing statement of the
 * same rule and the table of what each decorator carries.
 *
 * The rule: a span describes an operation, never the data the operation
 * carried. A trace is exported to a third-party backend, retained
 * there, and readable by everyone with access to it — a wider audience
 * than the database, cache, or upstream service the input was addressed
 * to. So a SQL statement, a cache key, a URL path, a URL's userinfo,
 * query string or fragment, and a bound parameter value all stop at
 * this boundary. What crosses it is one of four shapes:
 *
 * - a **fingerprint** — a SHA-256 prefix standing in for the value
 *   within one {@see FingerprintDomain}, so spans for one statement,
 *   key set, or URL group together;
 * - a **count** — how many parameters a statement bound, how many keys
 *   a batch touched;
 * - a **fixed-vocabulary descriptor** — a SQL keyword from
 *   {@see self::SQL_OPERATIONS}, an HTTP method from
 *   {@see self::HTTP_METHODS}, a search action from
 *   {@see self::SEARCH_ACTIONS}. A value outside its vocabulary
 *   resolves to that vocabulary's own fallback, never to itself, which
 *   is what keeps caller-supplied text out of span names and out of a
 *   backend's grouping cardinality;
 * - a **destination component** — the scheme, host and port
 *   {@see urlAttributes()} reads out of a URL. These are open-ended
 *   strings, not a closed vocabulary: what bounds them is that they
 *   name which service a call was addressed to, which comes from a
 *   deployment's own topology, while everything the call said to that
 *   service — path, query string, userinfo, fragment — stays behind.
 *   The cardinality they add is the number of hosts an application
 *   talks to.
 *
 * There is no opt-out. A decorator that exported the raw value on
 * request would put the choice in a configuration file, where the
 * consequence of getting it wrong is a credential sitting in an APM
 * backend, and where the unsafe setting is the one that reads like more
 * detail for free.
 *
 * An exception is an input too: a driver's own error message quotes the
 * failing statement and its literal values, and a stack trace carries
 * the arguments each frame was called with. {@see recordFailure()} is
 * what every decorator in this package reports a failure through, and
 * it records the exception's type and nothing else — the same choice
 * core's `Kinetis\Instrumentation\Telemetry` makes, for the same
 * reason, when it reports a contained backend failure. That type comes
 * from {@see exceptionType()} rather than off the object, since PHP
 * names an anonymous class after the file and line it was declared at.
 * The exception itself propagates unchanged, so an application that
 * wants the message logs it where its own redaction policy applies;
 * {@see Logging\TraceAwareLogger} puts the trace id on that log line,
 * which is what joins the two back together.
 *
 * @internal This is the package's own policy point, not consumer API:
 * its shape follows what the decorators need and changes with them.
 */
final class Redaction
{
    /**
     * 128 bits of SHA-256, the same width as a W3C trace id. Wide
     * enough that distinct values collide only past a scale no
     * application's traces reach; a shorter prefix trades that away for
     * nothing a reader gains.
     */
    private const int FINGERPRINT_LENGTH = 32;

    /** What {@see httpMethod()} answers for a method outside the set. */
    public const string METHOD_OTHER = '_OTHER';

    /**
     * The methods OTel's HTTP conventions name, which are also the only
     * ones this package puts on a span. Anything else — a typo, a
     * WebDAV verb, a string a caller assembled — becomes
     * {@see METHOD_OTHER}.
     *
     * @var list<non-empty-string>
     */
    private const array HTTP_METHODS = [
        'CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE',
    ];

    /**
     * Every keyword a span may be named after. A statement opening with
     * anything else is named `SQL` instead of having its first word
     * exported: the vocabulary is a closed set so that no
     * caller-supplied text reaches a span name through it, and so that
     * span names stay countable in a backend's own grouping.
     *
     * @var list<non-empty-string>
     */
    private const array SQL_OPERATIONS = [
        'ALTER', 'ANALYZE', 'BEGIN', 'CALL', 'COMMIT', 'COPY', 'CREATE', 'DEALLOCATE', 'DELETE', 'DESCRIBE',
        'DROP', 'EXECUTE', 'EXPLAIN', 'GRANT', 'INSERT', 'LOCK', 'MERGE', 'PREPARE', 'REINDEX', 'RELEASE',
        'REPLACE', 'REVOKE', 'ROLLBACK', 'SAVEPOINT', 'SELECT', 'SET', 'SHOW', 'START', 'TRUNCATE', 'UNLOCK',
        'UPDATE', 'UPSERT', 'USE', 'VACUUM', 'VALUES', 'WITH',
    ];

    /** What {@see searchAction()} answers for a path it does not recognize. */
    public const string SEARCH_ACTION_OTHER = 'request';

    /**
     * OpenSearch's own REST actions — the `_`-prefixed segment that
     * says what a request does, as opposed to the index names and
     * document ids around it. Only a segment in this list names a span;
     * every other path, including one made entirely of index and
     * document identifiers, resolves to {@see SEARCH_ACTION_OTHER}.
     *
     * @var list<non-empty-string>
     */
    private const array SEARCH_ACTIONS = [
        '_alias', '_aliases', '_analyze', '_bulk', '_cat', '_clone', '_close', '_cluster', '_count', '_create',
        '_delete_by_query', '_doc', '_explain', '_flush', '_forcemerge', '_ingest', '_mapping', '_mget',
        '_msearch', '_nodes', '_open', '_pit', '_refresh', '_reindex', '_render', '_rollover', '_script',
        '_scroll', '_search', '_settings', '_source', '_split', '_stats', '_tasks', '_template', '_update',
        '_update_by_query',
    ];

    /**
     * What {@see recordFailure()} reports for a throwable no named
     * class in its own ancestry describes.
     */
    public const string EXCEPTION_TYPE_OTHER = 'Throwable';

    /**
     * A bounded, stable stand-in for one value or an ordered list of
     * them, within $domain: two spans covering the same input in the
     * same domain carry the same fingerprint, so a backend groups them
     * the way it would have grouped the value, and neither span
     * carries the value.
     *
     * This is pseudonymous correlation data, not a secret. The digest
     * is unkeyed, so anyone holding a candidate value can confirm it by
     * hashing it — a value drawn from a small or enumerable set (a
     * sequential id, an address from a known list) stays guessable. The
     * guarantee is that the value is absent from the trace, not that it
     * becomes unknowable to someone who can already enumerate it.
     *
     * Members are framed by length rather than joined on a separator,
     * so no member's own bytes can be read as a boundary: `['a', 'b']`
     * and `["a\0b"]` are distinct inputs here, and any separator chosen
     * instead would make them the same. $domain is framed ahead of them
     * the same way, so a digest covers the kind of input as well as the
     * input itself: one string reaching a span as a cache key and as a
     * URL fingerprints differently in each place, and no member of a
     * value list can pose as a domain.
     *
     * @return non-empty-string
     */
    public static function fingerprint(FingerprintDomain $domain, string ...$values): string
    {
        $framed = self::frame($domain->value);

        foreach ($values as $value) {
            $framed .= self::frame($value);
        }

        /** @var non-empty-string */
        return substr(hash('sha256', $framed), 0, self::FINGERPRINT_LENGTH);
    }

    /**
     * One member of a fingerprint's input, prefixed with its own byte
     * length so that where it ends is part of what is hashed.
     */
    private static function frame(string $value): string
    {
        return strlen($value) . ':' . $value;
    }

    /**
     * $method as one of {@see HTTP_METHODS}, matched after uppercasing
     * so that a lowercase `get` reads as `GET` rather than as an
     * unknown verb, and {@see METHOD_OTHER} for everything else. The
     * value handed to the wrapped client or handler is untouched — this
     * governs only what a span says.
     *
     * @return non-empty-string
     */
    public static function httpMethod(string $method): string
    {
        $upper = strtoupper($method);

        return in_array($upper, self::HTTP_METHODS, true) ? $upper : self::METHOD_OTHER;
    }

    /**
     * The span name for $method: the method itself when it is one this
     * package knows, and `HTTP` otherwise, per OTel's own rule for a
     * request whose method it cannot name.
     *
     * @return non-empty-string
     */
    public static function httpSpanName(string $method): string
    {
        $normalized = self::httpMethod($method);

        return $normalized === self::METHOD_OTHER ? 'HTTP' : $normalized;
    }

    /**
     * The keyword $sql opens with when it is one this package names
     * spans after, and `SQL` for everything else — including a
     * statement whose first word is not a keyword at all.
     *
     * @return non-empty-string
     */
    public static function sqlOperation(string $sql): string
    {
        $keyword = strtok(ltrim($sql), " \t\n\r(");
        $upper = $keyword === false ? '' : strtoupper($keyword);

        return in_array($upper, self::SQL_OPERATIONS, true) ? $upper : 'SQL';
    }

    /**
     * The OpenSearch action $path performs, read as its last segment
     * that {@see SEARCH_ACTIONS} lists, and
     * {@see SEARCH_ACTION_OTHER} when it lists none. A path segment is
     * an index name, a document id, or an alias as often as it is an
     * action, so no segment names a span unless the vocabulary already
     * held it.
     *
     * @return non-empty-string
     */
    public static function searchAction(string $path): string
    {
        foreach (array_reverse(explode('/', $path)) as $segment) {
            if (in_array($segment, self::SEARCH_ACTIONS, true)) {
                return $segment;
            }
        }

        return self::SEARCH_ACTION_OTHER;
    }

    /**
     * The parts of $url that say which service a request went to,
     * rebuilt from `parse_url()`'s own components rather than by
     * editing the string: userinfo, query, fragment and path are never
     * read out of the parse at all, so a credential embedded in the
     * authority, a token passed as a query parameter, a fragment, and a
     * path segment carrying a user id, an email, a document id or a
     * signed token each have no route to a span through this. A URL
     * `parse_url()` rejects contributes nothing, and the caller still
     * has its fingerprint.
     *
     * A path is excluded rather than kept because a general-purpose
     * client has no route template to reduce it to: every distinct
     * identifier in it would arrive as its own attribute value.
     *
     * @return array<non-empty-string, string|int>
     */
    public static function urlAttributes(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return [];
        }

        $attributes = [];

        foreach (['scheme' => 'url.scheme', 'host' => 'server.address'] as $part => $attribute) {
            $value = $parts[$part] ?? '';

            if ($value !== '') {
                $attributes[$attribute] = $value;
            }
        }

        if (isset($parts['port'])) {
            $attributes['server.port'] = $parts['port'];
        }

        return $attributes;
    }

    /**
     * Marks $span as failed and records $e's type — as the status
     * description, and as an `exception` event's `exception.type`, the
     * attribute OTel's own exception convention names it under. The
     * message and the stack trace stay off the span; see this class's
     * own docblock for why.
     */
    public static function recordFailure(SpanInterface $span, Throwable $e): void
    {
        $type = self::exceptionType($e);

        $span->addEvent('exception', ['exception.type' => $type]);
        $span->setStatus(StatusCode::STATUS_ERROR, $type);
    }

    /**
     * The class name a span may carry for $e: its own when that is a
     * name, and otherwise the nearest ancestor whose is.
     *
     * PHP names an anonymous class after the file and line it was
     * declared at (`Foo@anonymous\0/app/src/Bar.php:12$0`), so
     * exporting one verbatim would put a source path, a line number and
     * a NUL byte on every span a library or a test double failed. Its
     * nearest named ancestor is what a reader can act on — an anonymous
     * `RuntimeException` subclass reports `RuntimeException` — and is a
     * name PHP itself declared, never anything a request supplied.
     *
     * Any resolved name is confirmed against PHP's own class-name
     * grammar before it is returned, so a future name shape this does
     * not anticipate falls back to {@see EXCEPTION_TYPE_OTHER} rather
     * than exporting whatever it holds.
     *
     * @return non-empty-string
     */
    private static function exceptionType(Throwable $e): string
    {
        if (self::isClassName($e::class)) {
            return $e::class;
        }

        for ($parent = get_parent_class($e); $parent !== false; $parent = get_parent_class($parent)) {
            if (self::isClassName($parent)) {
                return $parent;
            }
        }

        return self::EXCEPTION_TYPE_OTHER;
    }

    /**
     * Whether $class is a class name as PHP's own grammar defines one:
     * namespace-separated identifiers, and nothing a path, a line
     * number, a NUL byte or an exception message could hide in.
     */
    private static function isClassName(string $class): bool
    {
        $identifier = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

        return preg_match('/^' . $identifier . '(?:\\\\' . $identifier . ')*$/D', $class) === 1;
    }
}
