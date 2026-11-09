<?php

namespace App\Lib\Project;

use App\Models\User;
use App\System;

/**
 * Why a name cannot be given to a new project, worded the same wherever one
 * is created: create, clone and staging, over REST and the CLI.
 */
final class ProjectNameRefusal
{
    public const TAKEN = 'taken';

    public const UNAVAILABLE = 'unavailable';

    public static function taken(string $name): string
    {
        return "A project named '{$name}' already exists. Choose another name.";
    }

    public static function unavailable(string $name): string
    {
        return "'{$name}' is reserved or already used by the system. Choose another name.";
    }

    /** TAKEN when a project uses the name, UNAVAILABLE when the host does, null when it is free. */
    public static function reason(string $name, System $system): ?string
    {
        if (User::existsByUsername($name)) {
            return self::TAKEN;
        }

        return $system->isUsernameAvailable($name) ? null : self::UNAVAILABLE;
    }

    /** The refusal to show for $name, or null when it is free. */
    public static function messageFor(string $name, System $system): ?string
    {
        return match (self::reason($name, $system)) {
            self::TAKEN => self::taken($name),
            self::UNAVAILABLE => self::unavailable($name),
            default => null,
        };
    }
}
