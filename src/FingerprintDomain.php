<?php

declare(strict_types=1);

namespace Kinetis\Telemetry;

/**
 * Which kind of input a {@see Redaction::fingerprint()} covers. The
 * domain is hashed with the value, so one byte sequence reaching two
 * of these contexts — a cache key that is also a URL, a session id
 * that is also a document id — carries a different fingerprint in
 * each, and a reader holding a trace cannot join the two spans by
 * digest alone.
 *
 * Every case is declared here and named at the call site from this
 * enum, never assembled from an operation's own input: the set of
 * domains is what this file says it is, and a caller cannot widen it
 * at runtime or make two contexts share one by accident.
 *
 * A case's own value is part of every digest it covers, so changing
 * one re-fingerprints that context's whole history — a backend then
 * sees a new group where it had an old one.
 *
 * @internal Part of {@see Redaction}'s policy surface, not consumer
 * API: the cases follow what the decorators fingerprint.
 */
enum FingerprintDomain: string
{
    case SqlStatement = 'sql.statement';

    case CacheKeys = 'cache.keys';

    case HttpUrl = 'http.url';

    case SessionId = 'session.id';

    case SearchPath = 'search.path';
}
