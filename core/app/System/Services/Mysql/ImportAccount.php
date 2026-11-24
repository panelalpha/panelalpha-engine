<?php

namespace App\System\Services\Mysql;

/** The MariaDB account a copy's import signs in as, so the stream can do only what that account can on the target. */
final readonly class ImportAccount
{
    /** An empty $user asks sites-db for an account of its own, made and dropped around the import. */
    public function __construct(public string $user) {}

    /** For a database no account of its project is granted on. */
    public static function temporary(): self
    {
        return new self('');
    }
}
