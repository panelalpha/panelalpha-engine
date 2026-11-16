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

    public static function unavailable(string $name, array $holders = []): string
    {
        if ($holders === []) {
            return "'{$name}' is reserved or already used by the system. Choose another name.";
        }

        return "'{$name}' is still held on this server by ".self::holderPhrase($holders)
            .'. Remove what holds it, or choose another name.';
    }

    /** @param  list<string>  $holders  what {@see System::usernameHolders()} answers */
    private static function holderPhrase(array $holders): string
    {
        $phrases = array_map(
            static fn (string $holder): string => ($holder === System::HOLDER_ACCOUNT ? 'an ' : 'a ').$holder,
            $holders
        );

        return match (count($phrases)) {
            1 => $phrases[0],
            2 => $phrases[0].' and '.$phrases[1],
            default => implode(', ', array_slice($phrases, 0, -1)).' and '.$phrases[count($phrases) - 1],
        };
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
            self::UNAVAILABLE => self::heldOnHost($name, $system),
            default => null,
        };
    }

    /** The refusal for a name this host still holds, $system saying what holds it. */
    public static function heldOnHost(string $name, System $system): string
    {
        return self::unavailable($name, $system->usernameHolders($name));
    }
}
