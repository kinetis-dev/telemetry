<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests;

use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Telemetry\HttpClient\TracingHttpClient;
use Kinetis\Telemetry\Instrumentation\OtelTelemetry;
use Kinetis\Telemetry\Middleware\RequestSpanMiddleware;
use Kinetis\Telemetry\Persistence\TracingMysqlLink;
use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use Kinetis\Telemetry\Search\TracingOpenSearchTransport;
use Kinetis\Telemetry\SimpleCache\TracingSimpleCache;
use Kinetis\Telemetry\Tests\Fixtures\FakeMysqlLink;
use Kinetis\Telemetry\Tests\Fixtures\FakePsr18Client;
use Kinetis\Telemetry\Tests\Fixtures\FakeSimpleCache;
use Kinetis\Telemetry\Tests\Fixtures\RecordingHttpClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Every value below is planted: it appears nowhere else in the package,
 * so a single occurrence anywhere in an exported span is this policy
 * failing rather than a coincidence. Each test drives a real decorator
 * with one of them and then reads back everything a collector would
 * receive — span name, attributes, status description, and every event
 * with its own attributes — asserting the value is not in any of it,
 * while the same test proves the wrapped link, cache, or client was
 * handed the original input unaltered.
 *
 * `Kinetis\Telemetry\Redaction` states the policy; this is where it is
 * held to it.
 */
final class DataMinimizationTest extends TracingTestCase
{
    private const string SECRET_SQL_LITERAL = 'tok-reset-L3AK-d41d8cd98f00';

    private const string SECRET_PARAMETER = 'hunter2-P4SSW0RD-L3AK';

    private const string SECRET_DRIVER_MESSAGE = 'Duplicate entry L3AK-driver-echo-77b1 for key users.email';

    private const string SECRET_CACHE_KEY = 'password-reset:tok-L3AK-cache-9b1c3f';

    private const string SECRET_URL_PASSWORD = 'pw-L3AK-url-a1b2c3';

    private const string SECRET_URL_QUERY = 'ak-L3AK-query-d4e5f6';

    private const string SECRET_URL_FRAGMENT = 'frag-L3AK-778899';

    private const string SECRET_URL_PATH = 'reset-L3AK-path-0f7a21';

    private const string SECRET_URL = 'https://svc-user:' . self::SECRET_URL_PASSWORD
        . '@api.test/v1/orders/' . self::SECRET_URL_PATH . '?api_key=' . self::SECRET_URL_QUERY
        . '#' . self::SECRET_URL_FRAGMENT;

    private const string SECRET_METHOD = "GET\r\nX-Injected: mth-L3AK-verb-5c8d0e";

    private const string SECRET_REQUEST_PATH = '/invitations/accept/inv-L3AK-incoming-6b2f';

    private const string SECRET_INDEX = 'tenant-L3AK-index-3d9e';

    private const string SECRET_DOCUMENT_ID = 'doc-L3AK-ident-c07b';

    private const string SECRET_FAILURE_MESSAGE = 'refused for tok-L3AK-anon-2f4b';

    public function test_a_statements_literal_values_never_reach_an_exported_span(): void
    {
        $inner = new FakeMysqlLink();
        $sql = "SELECT * FROM password_resets WHERE token = '" . self::SECRET_SQL_LITERAL . "'";

        new TracingMysqlLink($inner, $this->tracerProvider)->query($sql);

        self::assertSame($sql, $inner->calls[0]['sql']);
        self::assertSame('SELECT', $this->span()->getName());
        $this->assertNothingExported(self::SECRET_SQL_LITERAL, 'password_resets');
    }

    public function test_bound_parameter_values_never_reach_an_exported_span(): void
    {
        $inner = new FakeMysqlLink();
        $params = [self::SECRET_PARAMETER, self::SECRET_SQL_LITERAL];

        new TracingMysqlLink($inner, $this->tracerProvider)
            ->execute('INSERT INTO users (password_hash, reset_token) VALUES (?, ?)', $params);

        self::assertSame($params, $inner->calls[0]['params']);
        self::assertSame(2, $this->span()->getAttributes()->get('kinetis.db.parameter_count'));
        $this->assertNothingExported(self::SECRET_PARAMETER, self::SECRET_SQL_LITERAL);
    }

    /**
     * A driver's own error message quotes the statement it rejected and
     * the value that caused the rejection, which is why the span
     * carries the exception's class and nothing else. The caller still
     * receives the whole exception, message and statement included.
     */
    public function test_a_failing_query_exports_neither_the_statement_nor_the_drivers_message(): void
    {
        $inner = new FakeMysqlLink(failWith: self::SECRET_DRIVER_MESSAGE);
        $sql = "INSERT INTO users (email) VALUES ('" . self::SECRET_SQL_LITERAL . "')";

        try {
            new TracingMysqlLink($inner, $this->tracerProvider)->query($sql);
            self::fail('Expected the query exception to propagate.');
        } catch (QueryException $e) {
            self::assertSame(self::SECRET_DRIVER_MESSAGE, $e->getMessage());
            self::assertSame($sql, $e->getQuery());
        }

        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(QueryException::class, $span->getStatus()->getDescription());
        self::assertSame('exception', $span->getEvents()[0]->getName());
        self::assertSame(QueryException::class, $span->getEvents()[0]->getAttributes()->get('exception.type'));
        $this->assertNothingExported(self::SECRET_SQL_LITERAL, self::SECRET_DRIVER_MESSAGE);
    }

    public function test_a_transactions_statements_and_parameters_never_reach_an_exported_span(): void
    {
        $inner = new FakeMysqlLink();
        $sql = "UPDATE users SET reset_token = '" . self::SECRET_SQL_LITERAL . "' WHERE id = ?";

        $transaction = new TracingMysqlLink($inner, $this->tracerProvider)->beginTransaction();
        $transaction->execute($sql, [self::SECRET_PARAMETER]);
        $transaction->commit();

        self::assertSame($sql, $inner->calls[0]['sql']);
        self::assertSame([self::SECRET_PARAMETER], $inner->calls[0]['params']);
        self::assertSame(['UPDATE', 'COMMIT'], array_map(static fn ($span) => $span->getName(), $this->spans()));
        $this->assertNothingExported(self::SECRET_SQL_LITERAL, self::SECRET_PARAMETER);
    }

    public function test_a_single_key_cache_operation_never_exports_the_key(): void
    {
        $inner = new FakeSimpleCache();
        $cache = new TracingSimpleCache($inner, $this->tracerProvider);

        $cache->set(self::SECRET_CACHE_KEY, 'irrelevant');
        $cache->get(self::SECRET_CACHE_KEY);
        $cache->has(self::SECRET_CACHE_KEY);
        $cache->delete(self::SECRET_CACHE_KEY);

        self::assertSame(array_fill(0, 4, self::SECRET_CACHE_KEY), $inner->seenKeys);
        self::assertCount(4, $this->spans());
        $this->assertNothingExported(self::SECRET_CACHE_KEY);
    }

    public function test_a_multi_key_cache_operation_never_exports_any_of_its_keys(): void
    {
        $inner = new FakeSimpleCache();
        $cache = new TracingSimpleCache($inner, $this->tracerProvider);
        $keys = [self::SECRET_CACHE_KEY, 'session:' . self::SECRET_URL_PASSWORD];

        $cache->setMultiple(array_fill_keys($keys, 'irrelevant'));
        iterator_to_array($cache->getMultiple($keys));
        $cache->deleteMultiple($keys);

        self::assertSame([...$keys, ...$keys, ...$keys], $inner->seenKeys);
        self::assertSame(2, $this->span()->getAttributes()->get('db.operation.batch.size'));
        $this->assertNothingExported(self::SECRET_CACHE_KEY, self::SECRET_URL_PASSWORD);
    }

    public function test_a_failing_cache_operation_exports_neither_the_key_nor_the_backends_message(): void
    {
        $message = 'READONLY You cannot write against a replica: ' . self::SECRET_CACHE_KEY;
        $inner = new FakeSimpleCache(failWith: $message);

        try {
            new TracingSimpleCache($inner, $this->tracerProvider)->get(self::SECRET_CACHE_KEY);
            self::fail('Expected the cache exception to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame([self::SECRET_CACHE_KEY], $inner->seenKeys);
        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
        $this->assertNothingExported(self::SECRET_CACHE_KEY);
    }

    /**
     * The four places an outgoing URL hides a credential or an
     * identifier — userinfo, a path segment, a query parameter, a
     * fragment — in one request. Scheme, host and port are what the
     * span keeps, and the wrapped client is handed the URL whole, since
     * it is the one that has to make the call.
     */
    public function test_an_outgoing_url_exports_no_userinfo_path_query_or_fragment(): void
    {
        $inner = new RecordingHttpClient();

        $response = new TracingHttpClient($inner, $this->tracerProvider)->request('GET', self::SECRET_URL);
        unset($response);
        gc_collect_cycles();

        self::assertSame(self::SECRET_URL, $inner->lastUrl);

        $span = $this->span();
        self::assertSame('https', $span->getAttributes()->get('url.scheme'));
        self::assertSame('api.test', $span->getAttributes()->get('server.address'));
        self::assertNull($span->getAttributes()->get('url.path'));
        $this->assertNothingExported(
            self::SECRET_URL_PASSWORD,
            self::SECRET_URL_PATH,
            self::SECRET_URL_QUERY,
            self::SECRET_URL_FRAGMENT,
            'svc-user',
        );
    }

    /**
     * A method is caller-supplied wherever a client is handed one from
     * configuration or from an inbound request, and a header-splitting
     * payload is the shape that abuses it. It names no span and reaches
     * no attribute, while the wrapped client receives the string it was
     * given and decides for itself whether to reject it.
     */
    public function test_a_caller_assembled_http_method_names_no_span_and_reaches_the_client_whole(): void
    {
        $inner = new RecordingHttpClient();

        $response = new TracingHttpClient($inner, $this->tracerProvider)
            ->request(self::SECRET_METHOD, 'https://api.test/');
        unset($response);
        gc_collect_cycles();

        self::assertSame(self::SECRET_METHOD, $inner->lastMethod);

        $span = $this->span();
        self::assertSame('HTTP', $span->getName());
        self::assertSame(Redaction::METHOD_OTHER, $span->getAttributes()->get('http.request.method'));
        // Both casings: the normalizer uppercases before matching, so a
        // leak through it would arrive uppercased rather than verbatim.
        $this->assertNothingExported(self::SECRET_METHOD, 'X-Injected', 'X-INJECTED');
    }

    /**
     * An incoming path is written by whoever made the request, and the
     * segments an application routes on are the identifiers it is
     * addressed by. The handler still receives the request untouched,
     * so routing and the controller see the path they were sent.
     */
    public function test_an_incoming_request_exports_no_form_of_its_path(): void
    {
        $seenTarget = null;
        $handler = new class($seenTarget) implements RequestHandlerInterface {
            public function __construct(private ?string &$seenTarget) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenTarget = $request->getUri()->getPath();

                return new Response(200);
            }
        };

        new RequestSpanMiddleware($this->tracerProvider)
            ->process(new ServerRequest('GET', 'https://app.test' . self::SECRET_REQUEST_PATH), $handler);

        self::assertSame(self::SECRET_REQUEST_PATH, $seenTarget);
        self::assertSame('GET', $this->span()->getName());
        $this->assertNothingExported(self::SECRET_REQUEST_PATH, 'inv-L3AK-incoming-6b2f');
    }

    /**
     * An OpenSearch path is index names and document ids around one
     * action segment, so only the action names the span. The index and
     * the id are the record the request addressed, which is the half a
     * trace never carries.
     */
    public function test_an_opensearch_path_exports_neither_its_index_nor_its_document_id(): void
    {
        $inner = new FakePsr18Client();
        $request = new Request('GET', 'http://localhost:9200/' . self::SECRET_INDEX . '/_doc/' . self::SECRET_DOCUMENT_ID);

        new TracingOpenSearchTransport($inner, $this->tracerProvider)->sendRequest($request);

        self::assertSame($request, $inner->lastRequest);

        $span = $this->span();
        self::assertSame('GET _doc', $span->getName());
        self::assertSame('_doc', $span->getAttributes()->get('db.operation.name'));
        self::assertNull($span->getAttributes()->get('url.path'));
        $this->assertNothingExported(self::SECRET_INDEX, self::SECRET_DOCUMENT_ID);
    }

    /**
     * The same path with no action segment at all: the fallback names
     * the span rather than the last segment, which would be the
     * document id itself.
     */
    public function test_an_opensearch_path_naming_no_action_exports_no_segment_of_it(): void
    {
        $inner = new FakePsr18Client();

        new TracingOpenSearchTransport($inner, $this->tracerProvider)
            ->sendRequest(new Request('PUT', 'http://localhost:9200/' . self::SECRET_INDEX . '/' . self::SECRET_DOCUMENT_ID));

        self::assertSame('PUT request', $this->span()->getName());
        $this->assertNothingExported(self::SECRET_INDEX, self::SECRET_DOCUMENT_ID);
    }

    /**
     * A PSR-16 key is an arbitrary string, a NUL byte included. The
     * fingerprint of one such key stays distinct from the fingerprint
     * of the key list it would split into if members were joined on
     * that byte, so a batch cannot be made to collide with a single key
     * by choosing what the key contains.
     */
    public function test_a_cache_key_carrying_a_nul_byte_neither_exports_it_nor_collides_with_a_key_list(): void
    {
        $inner = new FakeSimpleCache();
        $key = "session\0" . self::SECRET_CACHE_KEY;

        new TracingSimpleCache($inner, $this->tracerProvider)->get($key);

        self::assertSame([$key], $inner->seenKeys);
        self::assertSame(
            Redaction::fingerprint(FingerprintDomain::CacheKeys, $key),
            $this->span()->getAttributes()->get('kinetis.cache.key_fingerprint'),
        );
        self::assertNotSame(
            Redaction::fingerprint(FingerprintDomain::CacheKeys, $key),
            Redaction::fingerprint(FingerprintDomain::CacheKeys, 'session', self::SECRET_CACHE_KEY),
        );
        $this->assertNothingExported(self::SECRET_CACHE_KEY);
    }

    /**
     * The framework's routing hook is handed the raw request target
     * before a route is resolved. What describes the request on the
     * span is the template the router answers with, which is registered
     * code rather than anything the caller wrote.
     */
    public function test_the_route_match_hook_exports_the_matched_template_and_no_part_of_the_path(): void
    {
        $telemetry = new OtelTelemetry($this->tracerProvider);

        $token = $telemetry->routeMatchStarted('GET', self::SECRET_REQUEST_PATH);
        $telemetry->routeMatchEnded($token, '/invitations/accept/{token}');

        $span = $this->span();
        self::assertSame('/invitations/accept/{token}', $span->getAttributes()->get('http.route'));
        self::assertNull($span->getAttributes()->get('url.path'));
        $this->assertNothingExported(self::SECRET_REQUEST_PATH, 'inv-L3AK-incoming-6b2f');
    }

    public function test_a_synchronous_request_failure_exports_no_part_of_the_url_it_quotes(): void
    {
        $inner = new RecordingHttpClient(failWith: 'Could not resolve host for "' . self::SECRET_URL . '"');

        try {
            new TracingHttpClient($inner, $this->tracerProvider)->request('GET', self::SECRET_URL);
            self::fail('Expected the synchronous transport failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(self::SECRET_URL, $e->getMessage());
        }

        self::assertSame(self::SECRET_URL, $inner->lastUrl);
        self::assertSame(RuntimeException::class, $this->span()->getStatus()->getDescription());
        $this->assertNothingExported(self::SECRET_URL_PASSWORD, self::SECRET_URL_QUERY, self::SECRET_URL_FRAGMENT);
    }

    /**
     * The deferred half of the same rule: a transport error surfaces
     * when the response is consumed, through `TracingResponse`'s own
     * catch rather than the client's.
     */
    public function test_a_deferred_transport_failure_exports_no_part_of_the_url_it_quotes(): void
    {
        $transport = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused for ' . self::SECRET_URL]),
        );

        $response = new TracingHttpClient($transport, $this->tracerProvider)->request('GET', self::SECRET_URL);

        try {
            $response->getContent();
            self::fail('Expected the transport failure to propagate.');
        } catch (TransportExceptionInterface) {
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
        $this->assertNothingExported(self::SECRET_URL_PASSWORD, self::SECRET_URL_QUERY, self::SECRET_URL_FRAGMENT);
    }

    /**
     * The framework's own query hook reports the same statement the
     * decorator would, from inside the driver, and is held to the same
     * rule — otherwise installing the package would export in the
     * default configuration exactly what the opt-in decorator refuses
     * to.
     */
    public function test_the_query_hook_exports_neither_the_statement_nor_the_failure_that_ended_it(): void
    {
        $telemetry = new OtelTelemetry($this->tracerProvider);
        $sql = "SELECT * FROM password_resets WHERE token = '" . self::SECRET_SQL_LITERAL . "'";

        $token = $telemetry->queryDispatched('mysql', $sql);
        $telemetry->queryReaped($token, new QueryException(self::SECRET_DRIVER_MESSAGE, $sql));

        $span = $this->span();
        self::assertSame('SELECT', $span->getName());
        self::assertSame('SELECT', $span->getAttributes()->get('db.operation.name'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertNothingExported(self::SECRET_SQL_LITERAL, self::SECRET_DRIVER_MESSAGE, 'password_resets');
    }

    /**
     * A library, a test double, or a one-off guard routinely throws an
     * anonymous subclass, and PHP names one after the file and the line
     * it was declared at — a source path, a line number and a NUL byte,
     * carried in the one attribute OTel reserves for a type. What the
     * span reports is the nearest named ancestor: a reader still tells
     * a `RuntimeException` apart from a `QueryException`, with nothing
     * of the declaration site, the message or the stack beside it.
     */
    public function test_an_anonymous_failure_exports_its_named_ancestor_and_nothing_of_its_declaration(): void
    {
        $failure = new class(self::SECRET_FAILURE_MESSAGE) extends RuntimeException {};
        $handler = new class($failure) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $failure) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->failure;
            }
        };

        // The premise: the class name itself is a source location.
        self::assertStringContainsString(__FILE__, $failure::class);

        try {
            new RequestSpanMiddleware($this->tracerProvider)
                ->process(new ServerRequest('GET', 'https://app.test/'), $handler);
            self::fail('Expected the handler failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        $span = $this->span();
        self::assertSame(RuntimeException::class, $span->getStatus()->getDescription());
        self::assertSame(RuntimeException::class, $span->getEvents()[0]->getAttributes()->get('exception.type'));
        $this->assertNothingExported(
            $failure::class,
            '@anonymous',
            "\0",
            __FILE__,
            '.php',
            self::SECRET_FAILURE_MESSAGE,
            'tok-L3AK-anon-2f4b',
            'DataMinimizationTest',
            '#0',
        );
    }

    /**
     * Everything a collector receives for every span exported so far,
     * flattened: names, attributes, status descriptions, and each
     * event's own name and attributes.
     */
    private function exportedText(): string
    {
        $parts = [];

        foreach ($this->spans() as $span) {
            $parts[] = $span->getName();
            $parts[] = var_export($span->getAttributes()->toArray(), true);
            $parts[] = $span->getStatus()->getDescription();

            foreach ($span->getEvents() as $event) {
                $parts[] = $event->getName();
                $parts[] = var_export($event->getAttributes()->toArray(), true);
            }
        }

        return implode("\n", $parts);
    }

    private function assertNothingExported(string ...$secrets): void
    {
        $exported = $this->exportedText();
        self::assertNotSame('', $exported, 'Expected at least one exported span to inspect.');

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $exported,
                "'{$secret}' reached an exported span.",
            );
        }
    }
}
