<?php

namespace App\System\Project\Git;

/**
 * The askpass script and deploy key a git command installs into the account's
 * home, readable by the tenant. A killed worker skips their cleanup, so each
 * command sweeps what earlier ones left.
 */
final class CredentialFiles
{
    public const ASKPASS_PREFIX = '.panelalpha-git-askpass-';
    public const SSH_PREFIX = '.panelalpha-git-ssh-';

    /** No git command outlives its job, so an older file is a leftover. */
    public const STALE_SECONDS = \App\Support\JobBudget::SECONDS;

    /**
     * The credential files among one scandir() result, sorted.
     *
     * @param array<int, string>|false $entries
     * @return list<string>
     */
    public static function leftovers(array|false $entries): array
    {
        $found = array_values(array_filter(
            $entries === false ? [] : $entries,
            static fn (string $entry): bool => str_starts_with($entry, self::ASKPASS_PREFIX)
                || str_starts_with($entry, self::SSH_PREFIX)
        ));
        sort($found);

        return $found;
    }

    /** Older than any running git command; a fresh one may belong to one running now. */
    public static function isStale(string $path): bool
    {
        $mtime = @filemtime($path);

        return $mtime !== false && $mtime <= time() - self::STALE_SECONDS;
    }
}
