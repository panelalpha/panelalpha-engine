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
 * Disk use of every project, read on the host one project at a time and stored so reading never waits on `du`:
 * its files (home without ~/docker), and ~/docker with the root-owned images, build cache and volumes.
 */
class ProjectDisk
{
    /** @var callable(list<string>): string */
    private $runOnHost;

    /** @param (callable(list<string>): string)|null $runOnHost runs a host argv, returns stdout */
    public function __construct(?callable $runOnHost = null)
    {
        $this->runOnHost = $runOnHost ?? fn (array $argv): string => (new System)->exec($argv);
    }

    /** @return int rows written */
    public function sample(?Carbon $now = null): int
    {
        $at = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
        $written = 0;
        foreach (self::homes() as $username => $home) {
            $written += $this->sampleOne($username, $home, $at);
        }

        return $written;
    }

    /** @return array<string, string> username => home directory */
    private static function homes(): array
    {
        $system = new System;
        $homes = [];
        foreach (User::query()->pluck('username') as $username) {
            if (is_string($username) && $username !== '') {
                $homes[$username] = $system->projectHomeDirPath($username);
            }
        }

        return $homes;
    }

    /** One host run and one row per project, so a slow or failed walk loses only that project. */
    private function sampleOne(string $username, string $home, string $at): int
    {
        try {
            $bytes = self::parse(($this->runOnHost)(Privileged::host()->projectDisk([$username => $home])))[$username] ?? null;
        } catch (\Throwable $e) {
            Log::warning("Could not measure the disk use of {$username}: ".$e->getMessage());

            return 0;
        }
        if ($bytes === null) {
            return 0;
        }
        DB::table('project_disk_usage')->insert(['timestamp' => $at, 'username' => $username] + $bytes);

        return 1;
    }

    /**
     * The latest measurement, or null before the first one or while the table is not migrated: every project view
     * reads this, and that must not turn them all into 500s.
     *
     * @return ?array{files_bytes: ?int, docker_bytes: ?int, total_bytes: ?int, measured_at: string}
     */
    public static function latest(string $username): ?array
    {
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
