<?php

namespace App\Lib\Hub;

use App\Lib\Host\ProjectStats;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\ProjectListFacts;
use App\Lib\Project\ProjectOverview;
use App\Models\Backup;
use App\Models\HubConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The minute report: only the fields the Hub reads, built from what the engine's database holds and never from the
 * host, a daemon or a project's files. Every value is cut to what the Hub accepts, since one value it refuses refuses
 * the whole report.
 */
final class HubReport
{
    public const SCHEMA = 1;

    public const MAX_PROJECTS = 500;

    public const MAX_CONTAINERS = 100;

    public const MAX_BACKUP_PROJECTS = 50;

    public const MAX_BACKUPS = 20;

    public const WANT_BACKUPS = 'backups';

    private const USERNAME = '/\A[A-Za-z0-9._-]{1,128}\z/';

    private const HOSTNAME = '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,251}[A-Za-z0-9])?\z/';

    private const HOST_USAGE_STALE_MINUTES = 5;

    /** @return array<string, mixed> */
    public static function build(HubConnection $connection, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $projects = self::projects();

        $report = [
            'schema' => self::SCHEMA,
            'sent_at' => self::sentAt($connection, $now),
            'engine' => ['version' => HubValues::text(EngineIdentity::version(), 64)],
            'host' => self::host(HubHostFacts::of($connection, $now)),
            'usage' => self::usage($now),
            'projects' => self::projectRows($projects),
        ];
        if (in_array(self::WANT_BACKUPS, $connection->wanted(), true)) {
            $report['backups'] = self::backups($projects->take(self::MAX_BACKUP_PROJECTS));
        }

        return $report;
    }

    /**
     * Later than the last report's, to the millisecond, even for two built within one: the Hub drops a report whose
     * `sent_at` is not later than the last one it processed.
     */
    private static function sentAt(HubConnection $connection, Carbon $now): string
    {
        $last = $connection->last_sent_at === null ? null : Carbon::parse($connection->last_sent_at, 'UTC');
        $sentAt = $last !== null && $now->lte($last) ? $last->copy()->addMillisecond() : $now->copy();
        $text = $sentAt->format('Y-m-d\TH:i:s.v\Z');
        $connection->forceFill(['last_sent_at' => $text])->save();

        return $text;
    }

    /** @return list<string> the projects a report and a backfill cover */
    public static function projectUsernames(): array
    {
        return self::projects()->map(fn (User $user): string => (string) $user->username)->all();
    }

    /** @return Collection<int, User> */
    private static function projects(): Collection
    {
        return User::query()->with(['liveUser'])->orderBy('id')
            ->whereNotIn('username', User::systemUsernames())
            ->limit(self::MAX_PROJECTS)
            ->get()
            ->filter(fn (User $user): bool => preg_match(self::USERNAME, (string) $user->username) === 1)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function host(array $facts): array
    {
        $hostname = is_string($facts['hostname'] ?? null) && preg_match(self::HOSTNAME, $facts['hostname']) === 1 ? $facts['hostname'] : null;

        return [
            'hostname' => $hostname,
            'os' => HubValues::text($facts['os'] ?? null, 128),
            'kernel' => HubValues::text($facts['kernel'] ?? null, 128),
            'uptime_seconds' => HubValues::count($facts['uptime_seconds'] ?? null),
            'cpu_cores' => HubValues::count($facts['cpu_cores'] ?? null, 4096),
            'memory_total_bytes' => HubValues::count($facts['memory_total_bytes'] ?? null),
            'disk_total_bytes' => HubValues::count($facts['disk_total_bytes'] ?? null),
            'ipv4' => is_string($facts['ipv4'] ?? null) && filter_var($facts['ipv4'], FILTER_VALIDATE_IP) !== false ? $facts['ipv4'] : null,
        ];
    }

    /**
     * The average of the last complete minute of the collector's samples, or null when it has none from the last few
     * minutes.
     *
     * @return ?array<string, mixed>
     */
    private static function usage(Carbon $now): ?array
    {
        $newest = DB::table('server_metrics')
            ->where('timestamp', '>=', $now->copy()->subMinutes(self::HOST_USAGE_STALE_MINUTES)->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $now->copy()->startOfMinute()->format('Y-m-d H:i:s'))
            ->max('timestamp');
        if (! is_string($newest)) {
            return null;
        }
        $minute = Carbon::parse($newest, 'UTC')->startOfMinute();
        $row = DB::table('server_metrics')
            ->selectRaw('AVG(cpu_percent) AS cpu_percent, AVG(ram_percent) AS ram_percent, AVG(disk_percent) AS disk_percent, '
                .'AVG(net_in_bps) AS net_in_bps, AVG(net_out_bps) AS net_out_bps, '
                .'AVG(disk_read_bps) AS disk_read_bps, AVG(disk_write_bps) AS disk_write_bps')
            ->where('timestamp', '>=', $minute->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $minute->copy()->addMinute()->format('Y-m-d H:i:s'))
            ->first();

        return [
            'sampled_at' => $minute->toIso8601ZuluString(),
            'cpu_percent' => HubValues::percent($row->cpu_percent ?? null),
            'ram_percent' => HubValues::percent($row->ram_percent ?? null),
            'disk_percent' => HubValues::percent($row->disk_percent ?? null),
            'net_in_bps' => HubValues::count($row->net_in_bps ?? null),
            'net_out_bps' => HubValues::count($row->net_out_bps ?? null),
            'disk_read_bps' => HubValues::count($row->disk_read_bps ?? null),
            'disk_write_bps' => HubValues::count($row->disk_write_bps ?? null),
        ];
    }

    /**
     * @param  Collection<int, User>  $projects
     * @return list<array<string, mixed>>
     */
    private static function projectRows(Collection $projects): array
    {
        $facts = ProjectListFacts::load($projects);
        $usernames = $projects->map(fn (User $user): string => (string) $user->username)->all();
        $disks = self::disks($usernames);
        $containers = self::containers($usernames);
        $ssoRouted = HubSsoRoutes::areRendered();

        return $projects->map(fn (User $user): array => self::project(
            $user,
            $facts,
            $disks[(string) $user->username] ?? null,
            $containers[(string) $user->username] ?? [],
            $ssoRouted,
        ))->all();
    }

    /**
     * @param  ?array<string, mixed>  $disk
     * @param  list<array<string, mixed>>  $containers
     * @return array<string, mixed>
     */
    private static function project(User $user, ProjectListFacts $facts, ?array $disk, array $containers, bool $ssoRouted): array
    {
        $app = ProjectOverview::for($user, $facts);
        $details = $user->getDetails();
        $usage = $facts->usageOf($user);
        $config = $user->getConfig();

        return [
            'username' => (string) $user->username,
            'name' => HubValues::text($user->name, 255),
            'domain' => HubValues::text($user->domain, 253),
            'status' => HubValues::text($user->status, 32),
            'created_at' => self::createdAt($user),
            'protected' => $user->isProtected(),
            'staging_of' => HubValues::text($user->liveUser?->username, 128),
            'app' => [
                'status' => HubValues::text($app['status'], 32),
                'type' => HubValues::text($app['type'], 64),
                'label' => HubValues::text($app['label'], 128),
                'framework' => HubValues::text($app['framework'], 64),
                'public_url' => self::url($app['public_url']),
                'admin_sso' => $ssoRouted && $app['admin_sso'],
            ],
            'details' => [
                'health_healthy' => is_bool($details['health_healthy'] ?? null) ? $details['health_healthy'] : null,
                'deployment_status' => HubValues::text($details['deployment_status'] ?? null, 64),
                'git_repo' => self::gitRepo($details['git_repo'] ?? null),
                'git_branch' => HubValues::text($details['git_branch'] ?? null, 255),
                'git_commit' => HubValues::text($details['git_commit'] ?? null, 64),
            ],
            'config' => [
                'cpu_limit' => self::number($config['cpu_limit'] ?? null),
                'memory_limit' => self::number($config['memory_limit'] ?? null),
                'disk_space_limit' => self::number($config['disk_space_limit'] ?? null),
            ],
            'usage' => $usage === null ? null : self::sample($usage),
            'disk' => $disk === null ? null : $disk + ['limit_bytes' => self::limit(ResourceLimit::scaled($user->getDiskSpaceLimit(), 1024 * 1024))],
            'containers' => $containers,
        ];
    }

    private static function createdAt(User $user): ?string
    {
        $at = $user->getAttribute('created_at');

        return $at instanceof \DateTimeInterface ? Carbon::instance($at)->toIso8601ZuluString() : null;
    }

    /**
     * @param  list<string>  $usernames
     * @return array<string, array<string, mixed>>
     */
    private static function disks(array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }
        $newest = DB::table('project_disk_usage')->selectRaw('username, MAX(timestamp) AS newest')
            ->whereIn('username', $usernames)->groupBy('username');
        $rows = DB::table('project_disk_usage')
            ->joinSub($newest, 'newest', fn ($join) => $join->on('project_disk_usage.username', '=', 'newest.username')
                ->on('project_disk_usage.timestamp', '=', 'newest.newest'))
            ->get(['project_disk_usage.*']);

        $disks = [];
        foreach ($rows as $row) {
            $files = HubValues::count($row->files_bytes);
            $docker = HubValues::count($row->docker_bytes);
            $disks[(string) $row->username] = [
                'total_bytes' => $files === null && $docker === null ? null : (int) $files + (int) $docker,
                'files_bytes' => $files,
                'docker_bytes' => $docker,
                'measured_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
            ];
        }

        return $disks;
    }

    /**
     * Each project's containers from its newest recent sample, keyed for the Hub's charts by compose service.
     *
     * @param  list<string>  $usernames
     * @return array<string, list<array<string, mixed>>>
     */
    private static function containers(array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }
        $since = Carbon::now('UTC')->subSeconds(ProjectStats::STALE_SECONDS)->format('Y-m-d H:i:s');
        $newest = DB::table('project_container_metrics')->selectRaw('username, MAX(timestamp) AS newest')
            ->whereIn('username', $usernames)->where('timestamp', '>=', $since)->groupBy('username');
        $rows = DB::table('project_container_metrics')
            ->joinSub($newest, 'newest', fn ($join) => $join->on('project_container_metrics.username', '=', 'newest.username')
                ->on('project_container_metrics.timestamp', '=', 'newest.newest'))
            ->orderBy('project_container_metrics.name')
            ->get(['project_container_metrics.*']);

        $containers = [];
        foreach ($rows as $row) {
            $username = (string) $row->username;
            if (count($containers[$username] ?? []) >= self::MAX_CONTAINERS) {
                continue;
            }
            $name = HubValues::text($row->name, 255) ?? substr((string) $row->container_id, 0, 12);
            $containers[$username][] = [
                'service' => HubValues::text($row->service, 128) ?? HubValues::text($name, 128),
                'name' => $name,
            ] + self::sample([
                'cpu_percent' => $row->cpu_percent,
                'memory_bytes' => $row->memory_bytes,
                'memory_limit_bytes' => $row->memory_limit_bytes,
                'sampled_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
            ]);
        }

        return $containers;
    }

    /**
     * @param  Collection<int, User>  $projects
     * @return array<string, list<array<string, mixed>>>
     */
    private static function backups(Collection $projects): array
    {
        $backups = [];
        foreach ($projects as $user) {
            $rows = Backup::query()->where('user_id', $user->id)->with('items')->orderByDesc('created_at')->limit(self::MAX_BACKUPS)->get();
            $backups[(string) $user->username] = $rows->map(fn (Backup $backup): array => [
                'id' => (int) $backup->id,
                'async_status' => ['backup' => HubValues::text($backup->async_status['backup'] ?? null, 32)],
                'error' => HubValues::text($backup->error, 1024),
                'size_bytes' => (int) $backup->items->sum(fn ($item): int => max(0, (int) ($item->size_bytes ?? 0))),
                'updated_at' => $backup->updated_at->toIso8601ZuluString(),
            ])->all();
        }

        return $backups;
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private static function sample(array $sample): array
    {
        return [
            'cpu_percent' => HubValues::share($sample['cpu_percent'] ?? null),
            'memory_bytes' => HubValues::count($sample['memory_bytes'] ?? null),
            'memory_limit_bytes' => HubValues::count($sample['memory_limit_bytes'] ?? null),
            'sampled_at' => is_string($sample['sampled_at'] ?? null) ? $sample['sampled_at'] : null,
        ];
    }

    /** Only the address of the repository: no user, password, token, query or fragment. */
    public static function gitRepo(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._\/~-]+\z/', $url) === 1) {
            return HubValues::text($url, 512);
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return HubValues::text(strtolower($parts['scheme']).'://'.$parts['host'].$port.($parts['path'] ?? ''), 512);
    }

    private static function url(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 255 || preg_match('#\Ahttps?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? (float) $value : null);
    }

    private static function limit(int|float|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
