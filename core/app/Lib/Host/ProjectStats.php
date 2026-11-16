<?php

namespace App\Lib\Host;

use App\Models\User;
use App\System;
use App\System\Privileged;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CPU and memory of every project's DinD container and of each container inside it, read from the host's
 * cgroup files in one shell run (nothing executed inside an account), and read back for the API.
 */
class ProjectStats
{
    /** A project sample older than this is not "now". */
    public const STALE_SECONDS = 300;

    /** A host run is cut off well before the next minute's sample starts. */
    public const HOST_TIMEOUT_SECONDS = 45;

    /** @var callable(list<string>): string */
    private $runOnHost;

    /** @param (callable(list<string>): string)|null $runOnHost runs a host argv, returns stdout */
    public function __construct(?callable $runOnHost = null)
    {
        $this->runOnHost = $runOnHost ?? fn (array $argv): string => (new System)->exec($argv, [], self::HOST_TIMEOUT_SECONDS + 15);
    }

    /** @return array{projects: int, containers: int} rows written */
    public function sample(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $usernames = User::query()->pluck('username')->filter(fn ($u) => is_string($u) && $u !== '')->values()->all();
        if ($usernames === []) {
            return ['projects' => 0, 'containers' => 0];
        }

        try {
            $read = self::parse(($this->runOnHost)(Privileged::host()->projectStats($usernames, self::HOST_TIMEOUT_SECONDS)));
        } catch (\Throwable $e) {
            Log::warning('Could not read project stats: '.$e->getMessage());

            return ['projects' => 0, 'containers' => 0];
        }

        $at = $now->format('Y-m-d H:i:s');
        $previous = $this->previous($now);

        $projects = self::projectRows($read['projects'], $previous['projects'], $at, $now);
        $containers = self::containerRows($read['containers'], $previous['containers'], $at, $now);

        self::insertChunks('project_metrics', $projects);
        self::insertChunks('project_container_metrics', $containers);

        return ['projects' => count($projects), 'containers' => count($containers)];
    }

    /**
     * @param  array<string, array{memory: ?int, limit: ?int, usec: ?int}>  $read
     * @param  array<string, array{usec: int, at: Carbon}>  $previous
     * @return list<array<string, mixed>>
     */
    private static function projectRows(array $read, array $previous, string $at, Carbon $now): array
    {
        $projects = [];
        foreach ($read as $username => $p) {
            $projects[] = [
                'timestamp' => $at,
                'username' => $username,
                'cpu_percent' => self::cpuPercent($previous[$username] ?? null, $p['usec'], $now),
                'memory_bytes' => $p['memory'],
                'memory_limit_bytes' => $p['limit'],
                'cpu_usage_usec' => $p['usec'],
            ];
        }

        return $projects;
    }

    /**
     * @param  list<array{username: string, id: string, name: ?string, service: ?string, memory: ?int, limit: ?int, usec: ?int}>  $read
     * @param  array<string, array{usec: int, at: Carbon}>  $previous
     * @return list<array<string, mixed>>
     */
    private static function containerRows(array $read, array $previous, string $at, Carbon $now): array
    {
        $containers = [];
        foreach ($read as $c) {
            $containers[] = [
                'timestamp' => $at,
                'username' => $c['username'],
                'container_id' => $c['id'],
                'name' => $c['name'],
                'service' => $c['service'],
                'cpu_percent' => self::cpuPercent($previous[$c['id']] ?? null, $c['usec'], $now),
                'memory_bytes' => $c['memory'],
                'memory_limit_bytes' => $c['limit'],
                'cpu_usage_usec' => $c['usec'],
            ];
        }

        return $containers;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function insertChunks(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * Average every complete hour not averaged yet into the hourly tables. Safe to run as often as wanted:
     * the last averaged hour is recomputed, and nothing older than the minute rows' retention is read.
     *
     * @return int hourly rows written
     */
    public static function rollup(?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $to = $now->copy()->startOfHour();
        $oldest = $to->copy()->subHours(self::rawHours());
        $written = 0;

        $levels = [
            ['project_metrics', 'project_metrics_hourly', ['username']],
            ['project_container_metrics', 'project_container_metrics_hourly', ['username', 'container_id']],
        ];
        foreach ($levels as [$raw, $hourly, $keys]) {
            $written += self::rollupLevel($raw, $hourly, $keys, $oldest, $to);
        }

        return $written;
    }

    /** @param  list<string>  $keys */
    private static function rollupLevel(string $raw, string $hourly, array $keys, Carbon $oldest, Carbon $to): int
    {
        $last = DB::table($hourly)->max('timestamp');
        $first = $last !== null ? $last : DB::table($raw)->min('timestamp');
        if ($first === null) {
            return 0;
        }
        $from = Carbon::parse((string) $first, 'UTC')->startOfHour()->max($oldest);
        if ($from->gte($to)) {
            return 0;
        }

        $insert = self::hourlyRows($raw, $keys, $from, $to);
        self::replaceHourly($hourly, $from, $to, $insert);

        return count($insert);
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function hourlyRows(string $raw, array $keys, Carbon $from, Carbon $to): array
    {
        $select = implode(', ', $keys).', '.MetricBuckets::sql(3600).' AS bucket, AVG(cpu_percent) AS cpu_percent, '
            .'AVG(memory_bytes) AS memory_bytes, MAX(memory_limit_bytes) AS memory_limit_bytes, COUNT(*) AS samples'
            .($raw === 'project_container_metrics' ? ', MAX(name) AS name, MAX(service) AS service' : '');
        $rows = DB::table($raw)
            ->selectRaw($select)
            ->where('timestamp', '>=', $from->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $to->format('Y-m-d H:i:s'))
            ->groupBy($keys)
            ->groupByRaw('bucket')
            ->get();

        return $rows->map(function (object $row) use ($keys, $raw): array {
            return self::hourlyValues($row, $keys, $raw);
        })->all();
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function hourlyValues(object $row, array $keys, string $raw): array
    {
        $values = [
            'timestamp' => Carbon::createFromTimestampUTC((int) $row->bucket)->format('Y-m-d H:i:s'),
            'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
            'memory_bytes' => $row->memory_bytes === null ? null : (int) round((float) $row->memory_bytes),
            'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
            'samples' => (int) $row->samples,
        ];
        foreach ($keys as $key) {
            $values[$key] = (string) $row->$key;
        }
        if ($raw === 'project_container_metrics') {
            $values['name'] = $row->name;
            $values['service'] = $row->service;
        }

        return $values;
    }

    /** @param  list<array<string, mixed>>  $insert */
    private static function replaceHourly(string $hourly, Carbon $from, Carbon $to, array $insert): void
    {
        DB::transaction(function () use ($hourly, $from, $to, $insert): void {
            DB::table($hourly)
                ->where('timestamp', '>=', $from->format('Y-m-d H:i:s'))
                ->where('timestamp', '<', $to->format('Y-m-d H:i:s'))
                ->delete();
            foreach (array_chunk($insert, 200) as $chunk) {
                DB::table($hourly)->insert($chunk);
            }
        });
    }

    /** How long the per-minute rows are kept. */
    public static function rawHours(): int
    {
        return max(2, (int) env('PROJECT_METRICS_RAW_HOURS', 48));
    }

    /** How long the hourly averages are kept. */
    public static function hourlyDays(): int
    {
        return max(1, (int) env('PROJECT_METRICS_LIMIT_DAYS', 30));
    }

    /**
     * The DinD totals from each project's latest recent sample, in one query; a table not migrated yet
     * means no sample rather than a 500 on every project view.
     *
     * @param  list<string>  $usernames
     * @return array<string, array{cpu_percent: ?float, memory_bytes: ?int, memory_limit_bytes: ?int, sampled_at: string}>
     */
    public static function latestOf(array $usernames, ?Carbon $now = null): array
    {
        if ($usernames === []) {
            return [];
        }
        $since = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC')->subSeconds(self::STALE_SECONDS)->format('Y-m-d H:i:s');

        try {
            $rows = DB::table('project_metrics')
                ->joinSub(
                    DB::table('project_metrics')
                        ->selectRaw('username, MAX(timestamp) AS latest')
                        ->whereIn('username', $usernames)
                        ->where('timestamp', '>=', $since)
                        ->groupBy('username'),
                    'newest',
                    fn ($join) => $join->on('project_metrics.username', '=', 'newest.username')->on('project_metrics.timestamp', '=', 'newest.latest')
                )
                ->orderBy('project_metrics.id')
                ->get(['project_metrics.*']);
        } catch (QueryException) {
            return [];
        }

        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row->username] = [
                'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
                'memory_bytes' => $row->memory_bytes === null ? null : (int) $row->memory_bytes,
                'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
                'sampled_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
            ];
        }

        return $latest;
    }

    /**
     * A project's DinD totals over a history range.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{range: string, bucket_seconds: int, from: string, to: string}}
     */
    public static function history(string $username, string $range): array
    {
        $window = MetricBuckets::window($range);

        $rows = DB::table(self::table('project_metrics', $window['bucket']))
            ->selectRaw(MetricBuckets::sql($window['bucket']).' AS bucket, AVG(cpu_percent) AS cpu_percent, '
                .'AVG(memory_bytes) AS memory_bytes, MAX(memory_limit_bytes) AS memory_limit_bytes')
            ->where('username', $username)
            ->where('timestamp', '>=', $window['start']->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $window['end']->copy()->addSeconds($window['bucket'])->format('Y-m-d H:i:s'))
            ->groupByRaw('bucket')
            ->get();

        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[(int) $row->bucket] = self::bucketValues($row);
        }

        return [
            'data' => MetricBuckets::periods($window['start'], $window['end'], $window['bucket'], $byBucket),
            'meta' => MetricBuckets::meta($range, $window),
        ];
    }

    /**
     * Each container of a project with its history over a range.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{range: string, bucket_seconds: int, from: string, to: string}}
     */
    public static function containerHistory(string $username, string $range): array
    {
        $window = MetricBuckets::window($range);
        $rows = DB::table(self::table('project_container_metrics', $window['bucket']))
            ->selectRaw('container_id, '.MetricBuckets::sql($window['bucket']).' AS bucket, AVG(cpu_percent) AS cpu_percent, '
                .'AVG(memory_bytes) AS memory_bytes, MAX(memory_limit_bytes) AS memory_limit_bytes, MAX(name) AS name, MAX(service) AS service')
            ->where('username', $username)
            ->where('timestamp', '>=', $window['start']->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $window['end']->copy()->addSeconds($window['bucket'])->format('Y-m-d H:i:s'))
            ->groupBy('container_id')
            ->groupByRaw('bucket')
            ->get();

        $containers = [];
        foreach ($rows as $row) {
            $id = (string) $row->container_id;
            $containers[$id]['name'] ??= $row->name;
            $containers[$id]['service'] ??= $row->service;
            $containers[$id]['buckets'][(int) $row->bucket] = self::bucketValues($row);
        }

        $data = [];
        foreach ($containers as $id => $c) {
            $data[] = [
                'container_id' => $id,
                'name' => $c['name'],
                'service' => $c['service'],
                'history' => MetricBuckets::periods($window['start'], $window['end'], $window['bucket'], $c['buckets']),
            ];
        }

        return ['data' => $data, 'meta' => MetricBuckets::meta($range, $window)];
    }

    /**
     * Each container's latest sample; none when the project has no recent one.
     *
     * @return list<array<string, mixed>>
     */
    public static function latestContainers(string $username): array
    {
        $since = Carbon::now('UTC')->subSeconds(self::STALE_SECONDS)->format('Y-m-d H:i:s');
        $latest = DB::table('project_container_metrics')
            ->where('username', $username)
            ->where('timestamp', '>=', $since)
            ->max('timestamp');
        if ($latest === null) {
            return [];
        }

        return DB::table('project_container_metrics')
            ->where('username', $username)
            ->where('timestamp', $latest)
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'container_id' => (string) $row->container_id,
                'name' => $row->name,
                'service' => $row->service,
                'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
                'memory_bytes' => $row->memory_bytes === null ? null : (int) $row->memory_bytes,
                'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
                'sampled_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
            ])
            ->all();
    }

    /**
     * Busy share of one core between two samples (100 is one core); null for a first sample or a restart.
     *
     * @param  ?array{usec: int, at: Carbon}  $previous
     */
    public static function cpuPercent(?array $previous, ?int $usec, Carbon $now): ?float
    {
        if ($previous === null || $usec === null) {
            return null;
        }
        $elapsed = $now->getTimestamp() - $previous['at']->getTimestamp();
        $used = $usec - $previous['usec'];
        if ($elapsed <= 0 || $used < 0) {
            return null;
        }

        return round($used / ($elapsed * 1_000_000) * 100, 2);
    }

    /**
     * @return array{
     *   projects: array<string, array{memory: ?int, limit: ?int, usec: ?int}>,
     *   containers: list<array{username: string, id: string, name: ?string, service: ?string, memory: ?int, limit: ?int, usec: ?int}>
     * }
     */
    public static function parse(string $out): array
    {
        $projects = [];
        $containers = [];

        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $f = explode("\t", $line);
            if (($f[0] ?? '') === 'P' && count($f) === 6 && $f[1] !== '') {
                $projects[$f[1]] = self::usage($f[2], $f[3], $f[4], $f[5]);
            } elseif (($f[0] ?? '') === 'C' && count($f) === 9 && $f[1] !== '' && preg_match('/^[0-9a-f]{12,64}$/', $f[2]) === 1) {
                $containers[] = [
                    'username' => $f[1],
                    'id' => $f[2],
                    'name' => $f[3] !== '' ? $f[3] : null,
                    'service' => $f[4] !== '' ? $f[4] : null,
                ] + self::usage($f[5], $f[6], $f[7], $f[8]);
            }
        }

        return ['projects' => $projects, 'containers' => self::heldToProjectLimit($containers, $projects)];
    }

    /**
     * The project's DinD cgroup is every container's parent, so the lower of the two limits is the one that holds.
     *
     * @param  list<array{username: string, id: string, name: ?string, service: ?string, memory: ?int, limit: ?int, usec: ?int}>  $containers
     * @param  array<string, array{memory: ?int, limit: ?int, usec: ?int}>  $projects
     * @return list<array{username: string, id: string, name: ?string, service: ?string, memory: ?int, limit: ?int, usec: ?int}>
     */
    private static function heldToProjectLimit(array $containers, array $projects): array
    {
        return array_map(static function (array $container) use ($projects): array {
            $container['limit'] = self::lowerLimit($container['limit'], $projects[$container['username']]['limit'] ?? null);

            return $container;
        }, $containers);
    }

    private static function lowerLimit(?int $own, ?int $parent): ?int
    {
        if ($own === null || $parent === null) {
            return $own ?? $parent;
        }

        return min($own, $parent);
    }

    /**
     * Memory as `docker stats` reports it: the cgroup's less its droppable page cache; `max` is "no limit".
     *
     * @return array{memory: ?int, limit: ?int, usec: ?int}
     */
    private static function usage(string $current, string $max, string $inactive, string $usec): array
    {
        $current = ctype_digit($current) ? (int) $current : null;
        $inactive = ctype_digit($inactive) ? (int) $inactive : 0;

        return [
            'memory' => $current === null ? null : max(0, $current - $inactive),
            'limit' => ctype_digit($max) ? (int) $max : null,
            'usec' => ctype_digit($usec) ? (int) $usec : null,
        ];
    }

    /** Minute rows are kept two days and hourly averages the month, so an hour bucket reads the averages. */
    private static function table(string $minutes, int $bucket): string
    {
        return $bucket >= 3600 ? $minutes.'_hourly' : $minutes;
    }

    /** @return array{cpu_percent: ?float, memory_bytes: ?int, memory_limit_bytes: ?int} */
    private static function bucketValues(object $row): array
    {
        return [
            'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
            'memory_bytes' => $row->memory_bytes === null ? null : (int) round((float) $row->memory_bytes),
            'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
        ];
    }

    /**
     * The last CPU counter of each project and container, from the previous sample.
     *
     * @return array{projects: array<string, array{usec: int, at: Carbon}>, containers: array<string, array{usec: int, at: Carbon}>}
     */
    private function previous(Carbon $now): array
    {
        $since = $now->copy()->subSeconds(self::STALE_SECONDS)->format('Y-m-d H:i:s');
        $before = $now->format('Y-m-d H:i:s');
        $result = ['projects' => [], 'containers' => []];

        foreach ([['project_metrics', 'username', 'projects'], ['project_container_metrics', 'container_id', 'containers']] as [$table, $key, $slot]) {
            $rows = DB::table($table)
                ->where('timestamp', '>=', $since)
                ->where('timestamp', '<', $before)
                ->whereNotNull('cpu_usage_usec')
                ->orderBy('timestamp')
                ->get([$key, 'timestamp', 'cpu_usage_usec']);
            foreach ($rows as $row) {
                $result[$slot][(string) $row->$key] = [
                    'usec' => (int) $row->cpu_usage_usec,
                    'at' => Carbon::parse((string) $row->timestamp, 'UTC'),
                ];
            }
        }

        return $result;
    }
}
