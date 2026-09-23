<?php

namespace App\Integrations\Statistics;

use App\Integrations\GeoLocation\GeoLocation;
use App\Lib\Helpers\ContinentMap;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

class Awstats implements Statistics
{
    public const string LOG_FORMAT = '1';

    private string $configDir;

    public function __construct(
        private string $dataDir,
        ?string $configDir = null,
        private ?GeoLocation $geo = null,
        private string $awstatsBin = '/usr/lib/cgi-bin/awstats.pl',
        private string $mergeBin = '/usr/share/awstats/tools/logresolvemerge.pl',
    ) {
        $this->configDir = $configDir ?? dirname($this->dataDir) . '/awstats-config';
    }

    public function domainBandwidth(string $domain, string $start, string $end, string $groupBy): array
    {
        $days = $this->dayBytes($domain, $start, $end);
        if ($groupBy === 'month') {
            return $this->monthBuckets($days);
        }

        return $days;
    }

    public function projectBandwidth(array $domains, string $start, string $end, string $groupBy): array
    {
        $sum = [];
        foreach ($domains as $domain) {
            foreach ($this->domainBandwidth($domain, $start, $end, $groupBy) as $key => $bytes) {
                $sum[$key] = ($sum[$key] ?? 0) + $bytes;
            }
        }
        ksort($sum);

        return $sum;
    }

    public function projectCalendarMonthBytes(array $domains): int
    {
        $now = Carbon::now();
        $start = $now->copy()->startOfMonth()->toDateString();
        $end = $now->copy()->endOfMonth()->toDateString();
        $sum = 0;
        foreach ($this->projectBandwidth($domains, $start, $end, 'day') as $bytes) {
            $sum += $bytes;
        }

        return $sum;
    }

    public function domainVisitors(string $domain, string $start, string $end): array
    {
        $unique = 0;
        $total = 0;
        $visits = [];
        $visitsLength = [];

        foreach ($this->monthFiles($domain, $start, $end) as $contents) {
            $unique += $this->generalInt($contents, 'TotalUnique');
            foreach ($this->parseDayRows($contents) as $date => $row) {
                if ($date < $start || $date > $end) {
                    continue;
                }
                $total += $row['hits'];
                $visits[$date] = ($visits[$date] ?? 0) + $row['visits'];
            }
            foreach ($this->parseKeyedCounts($contents, 'SESSION') as $bucket => $count) {
                $visitsLength[$bucket] = ($visitsLength[$bucket] ?? 0) + $count;
            }
        }
        ksort($visits);

        return [
            'unique' => $unique,
            'total' => $total,
            'visits' => [
                'records' => $visits,
                'total' => array_sum($visits),
            ],
            'visits_length' => $visitsLength,
        ];
    }

    public function domainVisitorBreakdown(string $domain, string $dimension, string $start, string $end): array
    {
        $merged = [];
        foreach ($this->monthFiles($domain, $start, $end) as $contents) {
            foreach ($this->breakdownRows($contents, $dimension) as $row) {
                $key = ($row['code'] ?? '') . "\0" . $row['label'];
                if (!isset($merged[$key])) {
                    $merged[$key] = $row;
                    continue;
                }
                $merged[$key]['visits'] += $row['visits'];
            }
        }

        $rows = array_values($merged);
        usort($rows, static function (array $a, array $b): int {
            return $b['visits'] <=> $a['visits'] ?: strcmp($a['label'], $b['label']);
        });

        return $rows;
    }

    /**
     * @return list<array{label: string, visits: int, code?: string}>
     */
    private function breakdownRows(string $contents, string $dimension): array
    {
        return match ($dimension) {
            'pages' => $this->siderRows($contents),
            'os' => $this->labelledHits($contents, 'OS'),
            'browsers' => $this->labelledHits($contents, 'BROWSER'),
            'referrers' => $this->originRows($contents),
            'countries', 'continents', 'regions' => $this->geoRows($contents, $dimension),
            default => [],
        };
    }

    /**
     * SIDER rows are: URL, pages, bandwidth, entry, exit.
     * Pages are the view count. The next field is bytes, which is what a
     * prefix match on BEGIN_SIDER_404 used to surface as "visits".
     *
     * @return list<array{label: string, visits: int}>
     */
    private function siderRows(string $contents): array
    {
        $rows = [];
        foreach ($this->parseSection($contents, 'SIDER') as $parts) {
            if (count($parts) < 2) {
                continue;
            }
            $rows[] = [
                'label' => $parts[0],
                'visits' => (int) $parts[1],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, visits: int}>
     */
    private function labelledHits(string $contents, string $section): array
    {
        $rows = [];
        foreach ($this->parseSection($contents, $section) as $parts) {
            if (count($parts) < 2) {
                continue;
            }
            $rows[] = [
                'label' => $parts[0],
                'visits' => (int) $parts[1],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, visits: int, code: string}>
     */
    private function originRows(string $contents): array
    {
        $map = [
            'From0' => 'direct',
            'From1' => 'unknown',
            'From2' => 'search_engine',
            'From3' => 'website',
            'From4' => 'internal',
        ];
        $rows = [];
        foreach ($this->parseSection($contents, 'ORIGIN') as $parts) {
            $key = $map[$parts[0] ?? ''] ?? null;
            if ($key === null || count($parts) < 3) {
                continue;
            }
            $rows[] = [
                'label' => $key,
                'visits' => (int) $parts[2],
                'code' => $key,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, visits: int, code?: string}>
     */
    private function geoRows(string $contents, string $dimension): array
    {
        if ($this->geo === null) {
            return [];
        }

        $rows = [];
        foreach ($this->parseSection($contents, 'VISITOR') as $parts) {
            if (count($parts) < 3) {
                continue;
            }
            $place = $this->geoPlace($parts[0], $dimension);
            if ($place === null) {
                continue;
            }
            $rows[] = [
                'label' => $place['label'],
                'visits' => (int) $parts[2],
                'code' => $place['code'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{label: string, code: string}|null
     */
    private function geoPlace(string $ip, string $dimension): ?array
    {
        $record = $this->geo->lookup($ip);
        if ($record === null || ($record['country_code'] ?? '') === '') {
            return null;
        }
        $countryCode = $record['country_code'];

        return match ($dimension) {
            'countries' => [
                'label' => $record['country'] ?: $countryCode,
                'code' => $countryCode,
            ],
            'continents' => $this->continentPlace($countryCode),
            'regions' => $this->regionPlace($record),
            default => null,
        };
    }

    /**
     * @return array{label: string, code: string}|null
     */
    private function continentPlace(string $countryCode): ?array
    {
        $continent = ContinentMap::fromCountryCode($countryCode);
        if ($continent === null) {
            return null;
        }

        return $continent;
    }

    /**
     * @param array{country?: string, country_code?: string, region?: string, region_code?: string} $record
     * @return array{label: string, code: string}|null
     */
    private function regionPlace(array $record): ?array
    {
        $code = $record['region_code'] ?? '';
        $label = $record['region'] ?? '';
        if ($code === '' && $label === '') {
            return null;
        }

        return [
            'label' => $label !== '' ? $label : $code,
            'code' => $code !== '' ? $code : $label,
        ];
    }

    public function configureDomain(string $domain, array $aliases, string $logDirectory): void
    {
        $this->ensureDirectories();
        $logFile = rtrim($logDirectory, '/\\') . '/access.log';
        $hostAliases = implode(' ', $aliases);
        $body = implode("\n", [
            'LogFile="' . $this->confEscape($logFile) . '"',
            'LogType=W',
            'LogFormat=' . self::LOG_FORMAT,
            'SiteDomain="' . $this->confEscape($domain) . '"',
            'HostAliases="' . $this->confEscape($hostAliases) . '"',
            'DirData="' . $this->confEscape($this->dataDir) . '"',
            'DirCgi="/usr/lib/cgi-bin"',
            'DirIcons="/usr/share/awstats/icon"',
            'AllowToUpdateStatsFromBrowser=0',
            '',
        ]);
        file_put_contents($this->configPath($domain), $body);
    }

    public function forgetDomain(string $domain): void
    {
        $conf = $this->configPath($domain);
        if (is_file($conf)) {
            unlink($conf);
        }
        if (!is_dir($this->dataDir)) {
            return;
        }
        $pattern = '/^awstats\d+\.' . preg_quote($domain, '/') . '\.txt$/';
        foreach (scandir($this->dataDir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || preg_match($pattern, $name) !== 1) {
                continue;
            }
            $path = $this->dataDir . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function ingestDomain(string $domain, string $logDirectory, array $aliases = []): void
    {
        if (!is_file($this->configPath($domain))) {
            $this->configureDomain($domain, $aliases, $logDirectory);
        }

        $logs = $this->accessLogsForBackfill($logDirectory);
        if ($logs === []) {
            return;
        }

        $this->ensureDirectories();
        $merged = sys_get_temp_dir() . '/pa-awstats-merge-' . preg_replace('/[^a-z0-9.-]/i', '_', $domain) . '.log';
        if ($this->run(array_merge(['perl', $this->mergeBin], $logs), $merged) !== 0) {
            return;
        }

        $update = [
            'perl',
            $this->awstatsBin,
            '-config=' . $domain,
            '-configdir=' . $this->configDir,
            '-update',
            '-logfile=' . $merged,
        ];
        $this->run($update);
        $this->run(array_merge($update, ['-databasebreak=day']));
    }

    /**
     * The monthly DAY section only holds traffic AWStats counted as viewed, so
     * robots (curl, crawlers) are missing from it. The per-day database has
     * viewed and not-viewed bytes per hour in TIME; it wins where it exists,
     * and DAY is the fallback for days ingested before -databasebreak=day.
     *
     * @return array<string, int>
     */
    private function dayBytes(string $domain, string $start, string $end): array
    {
        $days = [];
        foreach ($this->monthFiles($domain, $start, $end) as $contents) {
            foreach ($this->parseDayRows($contents) as $date => $row) {
                if ($date < $start || $date > $end) {
                    continue;
                }
                $days[$date] = ($days[$date] ?? 0) + $row['bytes'];
            }
        }
        foreach ($this->dailyFiles($domain, $start, $end) as $date => $contents) {
            $days[$date] = $this->timeBytes($contents);
        }
        ksort($days);

        return $days;
    }

    /**
     * @return array<string, string> Y-m-d => per-day database contents
     */
    private function dailyFiles(string $domain, string $start, string $end): array
    {
        $files = [];
        foreach ($this->monthsCovering(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->startOfDay()) as $month) {
            $prefix = $this->dataDir . '/awstats' . $month->format('mY');
            $suffix = '.' . $domain . '.txt';
            foreach (glob($prefix . '[0-3][0-9]' . $suffix) ?: [] as $path) {
                $date = $month->format('Y-m-') . substr($path, strlen($prefix), 2);
                if ($date < $start || $date > $end) {
                    continue;
                }
                $files[$date] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    /**
     * TIME rows are: hour, pages, hits, bandwidth, then the same three for
     * not-viewed traffic.
     */
    private function timeBytes(string $contents): int
    {
        $bytes = 0;
        foreach ($this->parseSection($contents, 'TIME') as $parts) {
            $bytes += (int) ($parts[3] ?? 0) + (int) ($parts[6] ?? 0);
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private function monthFiles(string $domain, string $start, string $end): array
    {
        $files = [];
        foreach ($this->monthsCovering(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->startOfDay()) as $month) {
            $path = $this->monthlyFile($domain, $month);
            if (is_file($path)) {
                $files[] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    private function generalInt(string $contents, string $key): int
    {
        foreach ($this->parseSection($contents, 'GENERAL') as $parts) {
            if (($parts[0] ?? '') === $key) {
                return (int) ($parts[1] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @return array<string, array{hits: int, visits: int, bytes: int}>
     */
    private function parseDayRows(string $contents): array
    {
        $days = [];
        foreach ($this->parseSection($contents, 'DAY') as $parts) {
            if (count($parts) < 5 || !preg_match('/^\d{8}$/', $parts[0])) {
                continue;
            }
            $date = substr($parts[0], 0, 4) . '-' . substr($parts[0], 4, 2) . '-' . substr($parts[0], 6, 2);
            $days[$date] = [
                'hits' => (int) $parts[2],
                'bytes' => (int) $parts[3],
                'visits' => (int) $parts[4],
            ];
        }

        return $days;
    }

    /**
     * @return array<string, int>
     */
    private function parseKeyedCounts(string $contents, string $section): array
    {
        $counts = [];
        foreach ($this->parseSection($contents, $section) as $parts) {
            if (count($parts) < 2) {
                continue;
            }
            $key = $parts[0];
            $counts[$key] = ($counts[$key] ?? 0) + (int) $parts[1];
        }

        return $counts;
    }

    /**
     * @return list<list<string>>
     */
    private function parseSection(string $contents, string $name): array
    {
        $rows = [];
        $in = false;
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^BEGIN_' . preg_quote($name, '/') . '(?:\s|$)/', $line) === 1) {
                $in = true;
                continue;
            }
            if ($line === 'END_' . $name) {
                if ($in) {
                    break;
                }
                continue;
            }
            if (!$in || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if ($parts !== []) {
                $rows[] = $parts;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, int> $days
     * @return array<string, int>
     */
    private function monthBuckets(array $days): array
    {
        $months = [];
        foreach ($days as $date => $bytes) {
            $bucket = substr($date, 0, 7) . '-01';
            $months[$bucket] = ($months[$bucket] ?? 0) + $bytes;
        }
        ksort($months);

        return $months;
    }

    /**
     * @return list<Carbon>
     */
    private function monthsCovering(Carbon $from, Carbon $to): array
    {
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();
        $months = [];
        while ($cursor->lte($last)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }

    private function monthlyFile(string $domain, Carbon $month): string
    {
        return $this->dataDir . '/awstats' . $month->format('mY') . '.' . $domain . '.txt';
    }

    private function configPath(string $domain): string
    {
        return $this->configDir . '/awstats.' . $domain . '.conf';
    }

    private function ensureDirectories(): void
    {
        if (!is_dir($this->configDir) && !mkdir($concurrentDirectory = $this->configDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        if (!is_dir($this->dataDir) && !mkdir($concurrentDirectory = $this->dataDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
    }

    private function confEscape(string $value): string
    {
        return str_replace('"', '', $value);
    }

    /**
     * Current access.log plus rotated `.log` / `.gz` siblings from the last
     * 12 months. logrotate keeps a longer history; charts only need a year.
     *
     * @return list<string>
     */
    private function accessLogsForBackfill(string $logDirectory): array
    {
        if (!is_dir($logDirectory)) {
            return [];
        }
        $cutoff = Carbon::now()->subMonths(12)->timestamp;
        $files = [];
        foreach (scandir($logDirectory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !str_starts_with($name, 'access.log')) {
                continue;
            }
            if ($name !== 'access.log' && !str_ends_with($name, '.gz') && !str_ends_with($name, '.log')) {
                continue;
            }
            $path = $logDirectory . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            if ($name !== 'access.log' && filemtime($path) < $cutoff) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $argv
     */
    protected function run(array $argv, ?string $stdoutFile = null): int
    {
        $process = new Process($argv);
        $process->setTimeout(600);
        if ($stdoutFile !== null) {
            $handle = fopen($stdoutFile, 'wb');
            if ($handle === false) {
                return 1;
            }
            $process->run(static function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
            fclose($handle);
        } else {
            $process->run();
        }

        return $process->getExitCode() ?? 1;
    }
}
