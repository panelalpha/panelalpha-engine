<?php

namespace App\Lib\DeployHook;

use RuntimeException;

/** The checkout at `$path` (its path key) has no deploy hook to show, rotate or delete. */
final class DeployHookNotFound extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('This checkout has no deploy hook.');
    }
}
