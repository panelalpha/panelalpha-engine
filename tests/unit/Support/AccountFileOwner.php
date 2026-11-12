<?php

namespace Tests\Support;

/** An account whose files the test can own: the runner itself, or uid 1001 when it runs as root. */
trait AccountFileOwner
{
    protected static function accountUid(): int
    {
        return posix_getuid() === 0 ? 1001 : posix_getuid();
    }

    protected static function accountGid(): int
    {
        return posix_getuid() === 0 ? 1001 : posix_getgid();
    }

    protected static function ownByAccount(string $path): void
    {
        if (posix_getuid() === 0) {
            chown($path, self::accountUid());
            chgrp($path, self::accountGid());
        }
    }
}
