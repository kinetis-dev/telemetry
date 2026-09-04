<?php

declare(strict_types=1);

namespace Kinetis\Telemetry\Tests;

use Kinetis\Telemetry\FingerprintDomain;
use Kinetis\Telemetry\Redaction;
use PHPUnit\Framework\TestCase;

/**
 * The policy's own unit-level behavior. What it protects — that no
 * decorator lets a real input past it — is asserted end to end in
 * {@see DataMinimizationTest}.
 */
final class RedactionTest extends TestCase
{
    public function test_a_fingerprint_is_stable_bounded_and_distinct_per_value(): void
    {
        $domain = FingerprintDomain::CacheKeys;

        self::assertSame(Redaction::fingerprint($domain, 'a'), Redaction::fingerprint($domain, 'a'));
        self::assertNotSame(Redaction::fingerprint($domain, 'a'), Redaction::fingerprint($domain, 'b'));
        self::assertSame(32, strlen(Redaction::fingerprint($domain, 'a')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Redaction::fingerprint($domain, 'a'));
    }

    /**
     * Members are framed by length, so where a list divides is part of
     * what is hashed and no member's own bytes can pose as a boundary.
     * A separator would fail both halves of this: `['ab', 'c']` would
     * meet `['a', 'bc']`, and a member containing the separator itself
     * would meet the list that separator would split it into.
     */
    public function test_a_list_fingerprint_depends_on_where_its_members_divide(): void
    {
        $domain = FingerprintDomain::CacheKeys;

        self::assertNotSame(
            Redaction::fingerprint($domain, 'ab', 'c'),
            Redaction::fingerprint($domain, 'a', 'bc'),
        );
        self::assertNotSame(
            Redaction::fingerprint($domain, 'a', 'b'),
            Redaction::fingerprint($domain, 'b', 'a'),
        );
        self::assertNotSame(
            Redaction::fingerprint($domain, 'a', 'b'),
            Redaction::fingerprint($domain, "a\0b"),
        );
        self::assertNotSame(
            Redaction::fingerprint($domain, 'a', 'b'),
            Redaction::fingerprint($domain, 'a:b'),
        );
        self::assertNotSame(
            Redaction::fingerprint($domain, '1:a'),
            Redaction::fingerprint($domain, 'a'),
        );
    }

    /**
     * The domain is hashed with the value, so one byte sequence that
     * reaches spans in two contexts — a cache key that is also a URL —
     * carries an unrelated digest in each, and a trace reader cannot
     * join the two by comparing them. The domain is framed the way a
     * member is, so a value naming a domain cannot pose as one either.
     */
    public function test_a_fingerprint_separates_the_domains_it_covers(): void
    {
        $value = 'user:42:profile';

        $digests = array_map(
            static fn (FingerprintDomain $domain): string => Redaction::fingerprint($domain, $value),
            FingerprintDomain::cases(),
        );

        self::assertSame($digests, array_values(array_unique($digests)));
        self::assertNotSame(
            Redaction::fingerprint(FingerprintDomain::CacheKeys, $value),
            Redaction::fingerprint(FingerprintDomain::SqlStatement, FingerprintDomain::CacheKeys->value, $value),
        );
    }

    public function test_a_fingerprint_carries_none_of_the_value_it_covers(): void
    {
        self::assertStringNotContainsString(
            'hunter2',
            Redaction::fingerprint(FingerprintDomain::SqlStatement, 'password=hunter2'),
        );
    }

    public function test_an_opening_keyword_resolves_through_leading_whitespace_and_parentheses(): void
    {
        self::assertSame('SELECT', Redaction::sqlOperation("\n  select 1"));
        self::assertSame('SELECT', Redaction::sqlOperation('(SELECT 1) UNION (SELECT 2)'));
        self::assertSame('COMMIT', Redaction::sqlOperation('COMMIT'));
    }

    public function test_anything_outside_the_keyword_vocabulary_resolves_to_sql(): void
    {
        self::assertSame('SQL', Redaction::sqlOperation("'tok-secret-value'"));
        self::assertSame('SQL', Redaction::sqlOperation('PLEASE 1'));
        self::assertSame('SQL', Redaction::sqlOperation(''));
        self::assertSame('SQL', Redaction::sqlOperation('   '));
    }

    public function test_url_attributes_name_the_service_and_drop_everything_addressed_to_it(): void
    {
        self::assertSame(
            [
                'url.scheme' => 'https',
                'server.address' => 'api.test',
                'server.port' => 8443,
            ],
            Redaction::urlAttributes('https://user:pass@api.test:8443/v1/orders/42?token=secret#part'),
        );
    }

    public function test_url_attributes_omit_a_component_the_url_does_not_have(): void
    {
        self::assertSame(
            ['url.scheme' => 'https', 'server.address' => 'api.test'],
            Redaction::urlAttributes('https://api.test'),
        );
        self::assertSame([], Redaction::urlAttributes('/orders/42?token=secret'));
    }

    public function test_a_known_method_survives_normalization_and_anything_else_does_not(): void
    {
        self::assertSame('GET', Redaction::httpMethod('GET'));
        self::assertSame('DELETE', Redaction::httpMethod('delete'));
        self::assertSame(Redaction::METHOD_OTHER, Redaction::httpMethod('PROPFIND'));
        self::assertSame(Redaction::METHOD_OTHER, Redaction::httpMethod(''));
        self::assertSame(Redaction::METHOD_OTHER, Redaction::httpMethod('GET /secret HTTP/1.1'));
    }

    public function test_a_span_name_falls_back_to_http_for_a_method_outside_the_vocabulary(): void
    {
        self::assertSame('POST', Redaction::httpSpanName('post'));
        self::assertSame('HTTP', Redaction::httpSpanName('PROPFIND'));
        self::assertSame('HTTP', Redaction::httpSpanName(''));
    }

    public function test_a_search_action_comes_from_the_vocabulary_or_the_fixed_fallback(): void
    {
        self::assertSame('_search', Redaction::searchAction('/orders/_search'));
        self::assertSame('_doc', Redaction::searchAction('/orders/_doc/42'));
        self::assertSame(Redaction::SEARCH_ACTION_OTHER, Redaction::searchAction('/orders'));
        self::assertSame(Redaction::SEARCH_ACTION_OTHER, Redaction::searchAction('/orders/_made_up'));
        self::assertSame(Redaction::SEARCH_ACTION_OTHER, Redaction::searchAction(''));
    }

    /**
     * A URL `parse_url()` rejects yields no components rather than a
     * guess at them — the span still carries the fingerprint, which is
     * what a decorator sets independently of this.
     */
    public function test_an_unparseable_url_contributes_no_attributes(): void
    {
        self::assertSame([], Redaction::urlAttributes('https://user@:8443'));
    }
}
