<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\Statistics\Statistics;

final class FakeStatistics implements Statistics
{
    /** @var array<string, array<string, int>> */
    public array $domainSeries = [];

    public int $monthBytes = 0;

    /** @var array<string, array<string, mixed>> */
    public array $visitors = [];

    /** @var array<string, list<array{label: string, visits: int, code?: string}>> */
    public array $breakdowns = [];

    /** @var list<string> */
    public array $configured = [];

    /** @var list<string> */
    public array $forgotten = [];

    /** @var list<string> */
    public array $ingested = [];

    public function domainBandwidth(string $domain, string $start, string $end, string $groupBy): array
    {
        $days = $this->domainSeries[$domain] ?? [];
        $clipped = [];
        foreach ($days as $date => $bytes) {
            if ($date < $start || $date > $end) {
                continue;
            }
            $clipped[$date] = $bytes;
        }
        if ($groupBy === 'month') {
            $months = [];
            foreach ($clipped as $date => $bytes) {
                $bucket = substr($date, 0, 7) . '-01';
                $months[$bucket] = ($months[$bucket] ?? 0) + $bytes;
            }

            return $months;
        }

        return $clipped;
    }

    public function projectBandwidth(array $domains, string $start, string $end, string $groupBy): array
    {
        $sum = [];
        foreach ($domains as $domain) {
            foreach ($this->domainBandwidth($domain, $start, $end, $groupBy) as $key => $bytes) {
                $sum[$key] = ($sum[$key] ?? 0) + $bytes;
            }
        }

        return $sum;
    }

    public function projectCalendarMonthBytes(array $domains): int
    {
        if ($this->monthBytes !== 0 || $domains === []) {
            return $this->monthBytes;
        }

        $sum = 0;
        foreach ($domains as $domain) {
            foreach ($this->domainSeries[$domain] ?? [] as $bytes) {
                $sum += $bytes;
            }
        }

        return $sum;
    }

    public function domainVisitors(string $domain, string $start, string $end): array
    {
        return $this->visitors[$domain] ?? [
            'unique' => 0,
            'total' => 0,
            'visits' => ['records' => [], 'total' => 0],
            'visits_length' => [],
        ];
    }

    public function domainVisitorBreakdown(string $domain, string $dimension, string $start, string $end): array
    {
        return $this->breakdowns[$domain . ':' . $dimension] ?? [];
    }

    public function configureDomain(string $domain, array $aliases, string $logDirectory): void
    {
        $this->configured[] = $domain;
    }

    public function forgetDomain(string $domain): void
    {
        $this->forgotten[] = $domain;
    }

    public function ingestDomain(string $domain, string $logDirectory, array $aliases = []): void
    {
        $this->ingested[] = $domain;
    }
}
