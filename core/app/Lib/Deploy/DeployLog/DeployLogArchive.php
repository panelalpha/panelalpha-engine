<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Deploy\SafeName;
use InvalidArgumentException;

/**
 * Which deploys are kept per account: the newest DEPLOY_LOG_KEEP log files,
 * and the newest install's log whatever its age.
 *
 * `latest.json` is never pruned — it is the pointer the panel polls.
 */
final class DeployLogArchive
{
    public const KEEP_LOGS = 10;

    /** How many logs each account keeps: DEPLOY_LOG_KEEP, at least 1. */
    public static function keep(): int
    {
        return max(1, (int) config('deploy.log_keep', self::KEEP_LOGS));
    }

    /**
     * @return list<array{
     *   username: string, id: ?string, status: ?string, stage: ?string,
     *   log_count: int, total_bytes: int, started_at: ?int, finished_at: ?int
     * }>
     */
    public static function users(): array
    {
        $rows = array_map(self::userRow(...), self::accountDirectories());
        usort($rows, static fn (array $a, array $b): int => strcmp($a['username'], $b['username']));

        return $rows;
    }

    /**
     * @return list<array{id: string, is_latest: bool, status: ?string, bytes: int, mtime: int}>
     */
    public static function deploys(string $username): array
    {
        $paths = new DeployLogPaths($username);
        if (! is_dir($paths->directory())) {
            return [];
        }
        $latest = (new DeployStatus($paths))->read() ?? [];
        $latestId = self::text($latest, 'id');

        $rows = [];
        foreach ($paths->logFiles() as $file) {
            $id = basename($file, DeployLogPaths::LOG_EXTENSION);
            $isLatest = $latestId !== null && $id === $latestId;
            $rows[] = [
                'id' => $id,
                'is_latest' => $isLatest,
                'status' => $isLatest ? self::text($latest, 'status') : null,
                'bytes' => (int) @filesize($file),
                'mtime' => (int) @filemtime($file),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $rows;
    }

    /**
     * Delete all but the newest $keep log files for one account, sparing the
     * newest install's log.
     *
     * @return list<string> deleted absolute paths
     */
    public static function prune(string $username, int $keep = self::KEEP_LOGS, bool $dryRun = false): array
    {
        if ($keep < 1) {
            throw new InvalidArgumentException('keep must be >= 1');
        }

        $files = (new DeployLogPaths($username))->logFiles();
        if (count($files) <= $keep) {
            return [];
        }

        rsort($files);
        $install = self::newestInstall($files);
        $deleted = array_filter(
            array_slice($files, $keep),
            static fn (string $file): bool => $file !== $install
        );
        foreach ($deleted as $old) {
            if (! $dryRun) {
                @unlink($old);
            }
        }

        return array_values($deleted);
    }

    /**
     * The log a later failure is most often read against: how the project was first set up.
     *
     * @param  list<string>  $files  newest first
     */
    private static function newestInstall(array $files): ?string
    {
        foreach ($files as $file) {
            if (self::isInstall($file)) {
                return $file;
            }
        }

        return null;
    }

    private static function isInstall(string $file): bool
    {
        $start = DeployStartLine::read((new DeployLogReader($file))->head(DeployStartLine::HEAD));

        return $start['kind'] === DeployStartLine::KIND_INSTALL;
    }

    /**
     * @return array<string, list<string>> username => deleted paths
     */
    public static function pruneAll(int $keep = self::KEEP_LOGS, bool $dryRun = false): array
    {
        $result = [];
        foreach (self::users() as $row) {
            $deleted = self::prune($row['username'], $keep, $dryRun);
            if ($deleted !== []) {
                $result[$row['username']] = $deleted;
            }
        }

        return $result;
    }

    /** Remove every trace of a deleted account, by the SafeName rule as everywhere else, so a dotted name's logs go too. */
    public static function forget(string $username): void
    {
        if (! SafeName::isSafe($username)) {
            return;
        }

        $dir = DeployLogPaths::userDir($username);
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.'/'.$name;
            if ($name !== '.' && $name !== '..' && (is_file($path) || is_link($path))) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @return list<string> usernames with a log directory
     */
    private static function accountDirectories(): array
    {
        $base = DeployLogPaths::base();
        if (! is_dir($base)) {
            return [];
        }

        $names = [];
        foreach (scandir($base) ?: [] as $name) {
            if (is_dir($base.'/'.$name) && SafeName::isSafe($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array{
     *   username: string, id: ?string, status: ?string, stage: ?string,
     *   log_count: int, total_bytes: int, started_at: ?int, finished_at: ?int
     * }
     */
    private static function userRow(string $username): array
    {
        $paths = new DeployLogPaths($username);
        $latest = (new DeployStatus($paths))->read() ?? [];
        $files = $paths->logFiles();

        return [
            'username' => $username,
            'id' => self::text($latest, 'id'),
            'status' => self::text($latest, 'status'),
            'stage' => self::text($latest, 'stage'),
            'log_count' => count($files),
            'total_bytes' => array_sum(array_map(static fn (string $f): int => (int) @filesize($f), $files)),
            'started_at' => isset($latest['started_at']) ? (int) $latest['started_at'] : null,
            'finished_at' => isset($latest['finished_at']) ? (int) $latest['finished_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    private static function text(array $latest, string $key): ?string
    {
        $value = $latest[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
