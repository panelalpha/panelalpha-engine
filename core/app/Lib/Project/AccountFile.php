<?php

namespace App\Lib\Project;

/**
 * One file in a directory the account owns, judged the way the engine has to
 * judge it before reading: root can read straight through a link, so a file is
 * only the account's own log when it is a regular file, owned by the account and
 * reachable by no other name.
 */
final class AccountFile
{
    /**
     * The file's lstat when it is a regular file the account owns alone, null
     * otherwise. lstat does not follow a link, so a planted symlink is refused,
     * and nlink 1 refuses a hard link to a file kept elsewhere.
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
