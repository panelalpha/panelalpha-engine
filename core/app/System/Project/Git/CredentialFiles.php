<?php

namespace App\System\Project\Git;

use App\Support\JobBudget;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Log;

/**
 * The askpass script and deploy key a git command installs into the account's
 * home; a killed worker skips their cleanup and leaves a readable token.
 */
final class CredentialFiles
{
    public const ASKPASS_PREFIX = '.panelalpha-git-askpass-';

    public const SSH_PREFIX = '.panelalpha-git-ssh-';

    /** No git command outlives its job, so an older file is a leftover. */
    public const STALE_SECONDS = JobBudget::SECONDS;

    /** Where a command's files go: beside its work tree, or the home without one. */
    public static function directoryFor(string $home, ?string $workTree): string
    {
        return $workTree !== null ? dirname($workTree) : rtrim($home, '/');
    }

    /**
     * Remove the stale credentials in one directory: the per-command sweep.
     * Best effort, like {@see remove()}: a failed listing never fails the git command.
     *
     * @return list<string> the paths removed
     */
    public static function sweep(EngineSystem $system, string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        try {
            return self::removeStale($system, self::stalePaths($system, $dir, 1));
        } catch (\Throwable $e) {
            Log::warning('Could not sweep stale Git credential files', [
                'dir' => $dir,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Remove the stale credentials anywhere under $root: the scheduled sweep.
     *
     * @return list<string> the paths removed
     */
    public static function sweepTree(EngineSystem $system, string $root): array
    {
        return self::removeStale($system, self::stalePaths($system, $root, null));
    }

    /**
     * The scheduled sweep over every account home; a home that cannot be swept is logged and skipped.
     *
     * @return array{homes: int, removed: int}
     */
    public static function sweepHomes(EngineSystem $system): array
    {
        $homes = 0;
        $removed = 0;

        foreach (self::homes($system) as $home) {
            $homes++;
            try {
                $removed += count(self::sweepTree($system, $home));
            } catch (\Throwable $e) {
                Log::warning('Could not sweep git credential files', [
                    'home' => $home,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['homes' => $homes, 'removed' => $removed];
    }

    /** Remove one credential file, best effort: a failure never fails a git command. */
    public static function remove(EngineSystem $system, string $path): bool
    {
        try {
            $system->exec(['sudo', 'rm', '-f', '--', $path]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not remove a Git credential file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The account homes directly under the hosts directory.
     *
     * @return list<string>
     */
    private static function homes(EngineSystem $system): array
    {
        $root = rtrim($system->homesDirPath(), '/');
        $homes = [];
        foreach (@scandir($root) ?: [] as $entry) {
            $path = $root.'/'.$entry;
            if ($entry !== '.' && $entry !== '..' && is_dir($path)) {
                $homes[] = $path;
            }
        }

        return $homes;
    }

    /**
     * A file a running command or build removes while find walks past it is skipped, not an error.
     *
     * @return list<string>
     */
    private static function stalePaths(EngineSystem $system, string $root, ?int $depth): array
    {
        $find = [
            'sudo', 'find', rtrim($root, '/'),
            '-ignore_readdir_race',
            ...($depth === null ? ['-xdev'] : ['-maxdepth', (string) $depth]),
            '-type', 'f',
            '(', '-name', self::ASKPASS_PREFIX.'*', '-o', '-name', self::SSH_PREFIX.'*', ')',
            '-mmin', '+'.intdiv(self::STALE_SECONDS, 60),
            '-print0',
        ];

        return array_values(array_filter(
            explode("\0", $system->exec($find)),
            static fn (string $path): bool => $path !== '',
        ));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function removeStale(EngineSystem $system, array $paths): array
    {
        $removed = [];
        foreach ($paths as $path) {
            if (self::remove($system, $path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }
}
