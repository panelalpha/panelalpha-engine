<?php

namespace App\System;

/**
 * What a project may be called on any host: a Linux username's rule, since a project gets an OS user and a home
 * named after it, so a name the system would refuse the engine refuses first.
 */
final class UsernamePolicy
{
    /** At most 32 characters, starting with a letter -- `useradd`'s own rule. */
    private const PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /**
     * Names the base system or the engine's networks own, which an account would collide with: system users, then names
     * the proxy's resolver may answer (compose services, kept in sync by UsernamePolicyTest; templates' own; hosts-file loopback).
     */
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
        return self::isWellFormed($username) && ! self::isReserved($username);
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
