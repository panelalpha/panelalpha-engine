<?php

namespace App\Lib\Project;

/**
 * One file in a directory the account owns, judged before root reads it: root reads straight through a link,
 * so it is the account's own only when it is a regular file, owned by the account and reachable by no other name.
 */
final class AccountFile
{
    /**
     * The file's lstat when it is a regular file the account owns alone, null otherwise: lstat refuses
     * a planted symlink, and nlink 1 a hard link to a file kept elsewhere.
     *
     * @return array<string, int>|null
     */
    public static function ownedRegular(string $path, ?int $uid): ?array
    {
        if ($uid === null) {
            return null;
        }

        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            return null;
        }
        if (($stat['nlink'] ?? 0) !== 1) {
            return null;
        }
        if (($stat['uid'] ?? -1) !== $uid) {
            return null;
        }

        return $stat;
    }
}
