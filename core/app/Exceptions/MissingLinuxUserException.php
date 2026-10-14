<?php

namespace App\Exceptions;

use RuntimeException;

/** A project with no recorded Linux UID/GID: never act on it as www-data instead. */
class MissingLinuxUserException extends RuntimeException
{
    public static function for(string $username): self
    {
        return new self("Project '{$username}' has no Linux user recorded; rebuild it to create one.");
    }
}
