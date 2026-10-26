<?php

namespace App\Lib\Host;

use App\Models\User;
use App\System;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CPU and memory of every project, at two levels: the project's DinD
 * container as a whole (its daemon included), and each container running
 * inside it. Read from the host's cgroup files in one shell run -- no
 * `docker stats`, nothing executed inside an account.
 *
 * The inner containers' cgroups nest under the DinD container's, and their
 * names live in the inner daemon's data root, /home/<project>/docker.
 */
class ProjectStats
{
    /** A project sample older than this is not "now". */
    public const STALE_SECONDS = 300;

    /** @var callable(string): string */
    private $runOnHost;

    /** @param (callable(string): string)|null $runOnHost runs a bash script on the host, returns stdout */
    public function __construct(?callable $runOnHost = null)
    {
        $this->runOnHost = $runOnHost ?? fn (string $script): string => (new System)->execOnHost(['bash', '-c', $script]);
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
            $read = self::parse(($this->runOnHost)(self::script($usernames)));
        } catch (\Throwable $e) {
            Log::warning('Could not read project stats: '.$e->getMessage());

            return ['projects' => 0, 'containers' => 0];
        }

        $at = $now->format('Y-m-d H:i:s');
        $previous = $this->previous($now);

        $projects = [];
        foreach ($read['projects'] as $username => $p) {
            $projects[] = [
                'timestamp' => $at,
                'username' => $username,
                'cpu_percent' => self::cpuPercent($previous['projects'][$username] ?? null, $p['usec'], $now),
                'memory_bytes' => $p['memory'],
                'memory_limit_bytes' => $p['limit'],
                'cpu_usage_usec' => $p['usec'],
            ];
        }

        $containers = [];
        foreach ($read['containers'] as $c) {
            $containers[] = [
                'timestamp' => $at,
                'username' => $c['username'],
                'container_id' => $c['id'],
                'name' => $c['name'],
                'service' => $c['service'],
                'cpu_percent' => self::cpuPercent($previous['containers'][$c['id']] ?? null, $c['usec'], $now),
                'memory_bytes' => $c['memory'],
                'memory_limit_bytes' => $c['limit'],
                'cpu_usage_usec' => $c['usec'],
            ];
        }

        foreach (array_chunk($projects, 200) as $chunk) {
            DB::table('project_metrics')->insert($chunk);
        }
        foreach (array_chunk($containers, 200) as $chunk) {
            DB::table('project_container_metrics')->insert($chunk);
        }

        return ['projects' => count($projects), 'containers' => count($containers)];
    }

    /**
     * Average every complete hour that is not averaged yet into the hourly
     * tables. Safe to run as often as wanted: the last averaged hour is
     * recomputed, and nothing older than the minute rows' retention is
     * looked at.
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
            $last = DB::table($hourly)->max('timestamp');
            $first = $last !== null ? $last : DB::table($raw)->min('timestamp');
            if ($first === null) {
                continue;
            }
            $from = Carbon::parse((string) $first, 'UTC')->startOfHour()->max($oldest);
            if ($from->gte($to)) {
                continue;
            }

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

            $insert = $rows->map(function (object $row) use ($keys, $raw): array {
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
            })->all();

            DB::transaction(function () use ($hourly, $from, $to, $insert): void {
                DB::table($hourly)
                    ->where('timestamp', '>=', $from->format('Y-m-d H:i:s'))
                    ->where('timestamp', '<', $to->format('Y-m-d H:i:s'))
                    ->delete();
                foreach (array_chunk($insert, 200) as $chunk) {
                    DB::table($hourly)->insert($chunk);
                }
            });
            $written += count($insert);
        }

        return $written;
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
     * The DinD totals from the latest sample, or null when there is no recent one.
     *
     * @return ?array{cpu_percent: ?float, memory_bytes: ?int, memory_limit_bytes: ?int, sampled_at: string}
     */
    public static function latest(string $username, ?Carbon $now = null): ?array
    {
        $since = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC')->subSeconds(self::STALE_SECONDS);
        // Every project view reads this: a table not migrated yet means no
        // sample, not a 500 on all of them.
        try {
            $row = DB::table('project_metrics')
                ->where('username', $username)
                ->where('timestamp', '>=', $since->format('Y-m-d H:i:s'))
                ->orderByDesc('timestamp')
                ->first();
        } catch (QueryException) {
            return null;
        }

        return $row === null ? null : [
            'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
            'memory_bytes' => $row->memory_bytes === null ? null : (int) $row->memory_bytes,
            'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
            'sampled_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
        ];
    }

    /**
     * Busy share of one core between two samples: 100 is one core, as
     * `docker stats` counts it. Null for a first sample, or when the counter
     * went backwards because the container restarted.
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
     * One run on the host. For each project container that is running: a `P`
     * line for its cgroup, and a `C` line for each container nested in it.
     *
     * @param  list<string>  $usernames
     */
    public static function script(array $usernames): string
    {
        $want = escapeshellarg(' '.implode(' ', $usernames).' ');

        return str_replace('__WANT__', $want, <<<'SH'
set -u
want=__WANT__
mem() {
    cur=$(cat "$1/memory.current" 2>/dev/null || true)
    max=$(cat "$1/memory.max" 2>/dev/null || true)
    inactive=$(awk '$1=="inactive_file"{print $2}' "$1/memory.stat" 2>/dev/null || true)
    usec=$(awk '$1=="usage_usec"{print $2}' "$1/cpu.stat" 2>/dev/null || true)
    printf '%s\t%s\t%s\t%s' "${cur:-}" "${max:-}" "${inactive:-}" "${usec:-}"
}
docker ps --no-trunc --format '{{.ID}} {{.Names}}' 2>/dev/null | while read -r id name; do
    case "$want" in *" $name "*) ;; *) continue ;; esac
    dir=
    for c in "/sys/fs/cgroup/system.slice/docker-$id.scope" "/sys/fs/cgroup/docker/$id"; do
        [ -d "$c" ] && { dir=$c; break; }
    done
    [ -n "$dir" ] || continue
    printf 'P\t%s\t%s\n' "$name" "$(mem "$dir")"
    for c in "$dir"/docker/*/; do
        [ -d "$c" ] || continue
        cid=$(basename "$c")
        cfg="/home/$name/docker/containers/$cid/config.v2.json"
        cname=$(grep -o '"Name":"[^"]*"' "$cfg" 2>/dev/null | head -n1 | cut -d'"' -f4)
        svc=$(grep -o '"com.docker.compose.service":"[^"]*"' "$cfg" 2>/dev/null | head -n1 | cut -d'"' -f4)
        printf 'C\t%s\t%s\t%s\t%s\t%s\n' "$name" "$cid" "${cname#/}" "$svc" "$(mem "$c")"
    done
done
exit 0
SH);
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

        return ['projects' => $projects, 'containers' => $containers];
    }

    /**
     * Memory as `docker stats` reports it: what the cgroup holds less the page
     * cache it can drop at once. `max` is "no limit".
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

    /**
     * The last CPU counter of each project and container, from the sample
     * before this one.
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
