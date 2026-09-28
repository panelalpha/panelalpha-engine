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

    /** Names the base system already owns. Taking one would collide with it. */
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
