<?php

namespace App\Integrations\Statistics;

/**
 * Bandwidth and visitor reports for hosted domains.
 *
 * AWStats is the first adapter. Controllers, MCP and CLI go through this
 * interface so a later provider does not need new HTTP routes.
 */
interface Statistics
{
    /**
     * Daily or monthly transfer for one domain, in bytes.
     *
     * Keys are Y-m-d (the first of the month when grouping by month).
     * Missing or empty provider data yields an empty array, not an error.
     *
     * @return array<string, int>
     */
    public function domainBandwidth(string $domain, string $start, string $end, string $groupBy): array;

    /**
     * Transfer for a project: the sum of the given domains.
     *
     * @param list<string> $domains
     * @return array<string, int>
     */
    public function projectBandwidth(array $domains, string $start, string $end, string $groupBy): array;

    /**
     * Calendar-month transfer for a project in the host timezone, in bytes.
     *
     * @param list<string> $domains
     */
    public function projectCalendarMonthBytes(array $domains): int;

    /**
     * Visitor overview for a domain.
     *
     * @return array{
     *   unique: int,
     *   total: int,
     *   visits: array{records: array<string, int>, total: int},
     *   visits_length: array<string, int>
     * }
     */
    public function domainVisitors(string $domain, string $start, string $end): array;

    /**
     * Visitor breakdown for a domain and dimension.
     *
     * @return list<array{label: string, visits: int, code?: string}>
     */
    public function domainVisitorBreakdown(string $domain, string $dimension, string $start, string $end): array;

    /**
     * Ensure provider config for a domain (idempotent).
     *
     * @param list<string> $aliases
     */
    public function configureDomain(string $domain, array $aliases, string $logDirectory): void;

    /**
     * Remove that domain's config and stored statistics. Other domains are untouched.
     */
    public function forgetDomain(string $domain): void;

    /**
     * Ingest host access logs for the domain into the provider store.
     * Writes config if it is missing. Does not run when there are no logs.
     *
     * @param list<string> $aliases
     */
    public function ingestDomain(string $domain, string $logDirectory, array $aliases = []): void;
}
