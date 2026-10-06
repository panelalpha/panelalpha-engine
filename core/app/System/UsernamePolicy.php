<?php

namespace App\System;

/**
 * What a project may be called, independent of what this host already holds.
 *
 * The rule is a Linux username's: a project gets an OS user and a home
 * directory named after it, so a name the system would refuse is a name the
 * engine has to refuse first.
 */
final class UsernamePolicy
{
    /** At most 32 characters, starting with a letter -- `useradd`'s own rule. */
    private const PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** Names the base system or the engine's networks already own. Taking one would collide with it. */
    private const RESERVED = [
        'root',
        'daemon',
        'bin',
        'sys',
        'sync',
        'games',
        'man',
        'lp',
        'mail',
        'news',
        'uucp',
        'proxy',
        'www-data',
        'backup',
        'list',
        'irc',
        'nobody',
        'systemd-network',
        'systemd-resolve',
        // Names the proxy's resolver may already answer, so an account named so would
        // share it: the engine's compose services (kept in sync by UsernamePolicyTest),
        // the account templates' own (`dind`, `php`), and the hosts file's loopback names.
        'core',
        'ftp',
        'sftp',
        'lighthouse',
        'metrics',
        'dind',
        'php',
        'localhost',
        'localhost4',
        'localhost6',
    ];

    /** Whether the name is one a project could have, on any host. */
    public static function isAcceptable(string $username): bool
    {
        return self::isWellFormed($username) && !self::isReserved($username);
    }

    public static function isWellFormed(string $username): bool
    {
        return (bool) preg_match(self::PATTERN, $username);
    }

    public static function isReserved(string $username): bool
    {
        return in_array($username, self::RESERVED, true);
    }

    /** @return list<string> */
    public static function reserved(): array
    {
        return self::RESERVED;
    }
}
