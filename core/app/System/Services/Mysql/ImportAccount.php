<?php

namespace App\System\Services\Mysql;

/** The target's own MariaDB account a copy's import signs in as, so the stream can do only what that account can. */
final readonly class ImportAccount
{
    public function __construct(public string $user)
    {
        if ($user === '') {
            throw new \RuntimeException('A database copy imports as a named account');
        }
    }
}
