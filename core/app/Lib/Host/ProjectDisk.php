<?php

namespace App\Lib\Host;

use App\Models\User;
use App\System;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disk use of every project, measured on the host in one shell run and stored,
 * so reading it never waits on `du`. Two parts: the project's files (its home
 * without ~/docker) and its own Docker's data in ~/docker, which is root-owned
 * and holds images, build cache and volumes -- usually most of the total.
 */
class ProjectDisk
{
    /** @var callable(string): string */
    private $runOnHost;

    /** @param (callable(string): string)|null $runOnHost runs a bash script on the host, returns stdout */
    public function __construct(?callable $runOnHost = null)
    {
        $this->runOnHost = $runOnHost ?? fn (string $script): string => (new System)->execOnHost(['bash', '-c', $script]);
    }

    /** @return int rows written */
    public function sample(?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $system = new System;
        $homes = [];
        foreach (User::query()->pluck('username') as $username) {
            if (is_string($username) && $username !== '') {
                $homes[$username] = $system->projectHomeDirPath($username);
            }
        }
        if ($homes === []) {
            return 0;
        }

        try {
            $read = self::parse(($this->runOnHost)(self::script($homes)));
        } catch (\Throwable $e) {
            Log::warning('Could not measure project disk use: '.$e->getMessage());

            return 0;
        }

        $at = $now->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($read as $username => $bytes) {
            if (isset($homes[$username])) {
                $rows[] = ['timestamp' => $at, 'username' => $username] + $bytes;
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('project_disk_usage')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * The latest measurement, or null before the first one.
     *
     * @return ?array{files_bytes: ?int, docker_bytes: ?int, total_bytes: ?int, measured_at: string}
     */
    public static function latest(string $username): ?array
    {
        // Every project view reads this: a table not migrated yet must not
        // turn them all into 500s, it only means nothing was measured.
        try {
            $row = DB::table('project_disk_usage')
                ->where('username', $username)
                ->orderByDesc('timestamp')
                ->first();
        } catch (QueryException) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $files = $row->files_bytes === null ? null : (int) $row->files_bytes;
        $docker = $row->docker_bytes === null ? null : (int) $row->docker_bytes;

        return [
            'files_bytes' => $files,
            'docker_bytes' => $docker,
            'total_bytes' => $files === null && $docker === null ? null : (int) $files + (int) $docker,
            'measured_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
        ];
    }

    /** How long measurements are kept. */
    public static function daysKept(): int
    {
        return max(1, (int) env('PROJECT_DISK_USAGE_DAYS', 30));
    }

    /**
     * One `D` line per project home that exists: bytes on disk of the files
     * and of ~/docker (empty when there is none). Idle I/O priority, one
     * filesystem, so a busy host barely notices.
     *
     * @param  array<string, string>  $homes  username => home directory
     */
    public static function script(array $homes): string
    {
        $pairs = '';
        foreach ($homes as $username => $home) {
            $pairs .= escapeshellarg($username).' '.escapeshellarg(rtrim($home, '/')).' ';
        }

        return str_replace('__PAIRS__', $pairs, <<<'SH'
set -u
low=
command -v nice >/dev/null 2>&1 && low="nice -n 19"
command -v ionice >/dev/null 2>&1 && low="$low ionice -c3"
set -- __PAIRS__
while [ $# -ge 2 ]; do
    name=$1 home=$2
    shift 2
    [ -d "$home" ] || continue
    files=$($low du -sx -B1 --exclude="$home/docker" "$home" 2>/dev/null | cut -f1)
    docker=
    [ -d "$home/docker" ] && docker=$($low du -sx -B1 "$home/docker" 2>/dev/null | cut -f1)
    printf 'D\t%s\t%s\t%s\n' "$name" "$files" "$docker"
done
exit 0
SH);
    }

    /** @return array<string, array{files_bytes: ?int, docker_bytes: ?int}> */
    public static function parse(string $out): array
    {
        $read = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $f = explode("\t", $line);
            if (($f[0] ?? '') !== 'D' || count($f) !== 4 || $f[1] === '') {
                continue;
            }
            $files = ctype_digit($f[2]) ? (int) $f[2] : null;
            $docker = ctype_digit($f[3]) ? (int) $f[3] : null;
            if ($files === null && $docker === null) {
                continue;
            }
            $read[$f[1]] = ['files_bytes' => $files, 'docker_bytes' => $docker];
        }

        return $read;
    }
}
