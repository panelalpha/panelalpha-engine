<?php

namespace App\System;

/**
 * The engine's own Compose project. Compose prefixes every container and
 * volume of the stack with its name, so code that needs one of those names
 * builds it here instead of spelling the prefix out.
 */
final class ComposeProject
{
    /**
     * Nothing sets a project name, so Compose uses the basename of the
     * directory holding docker-compose.yml ({@see EnginePaths::ENGINE_DIR}).
     */
    public const NAME = 'shared-hosting';

    /** The name Compose gives a service's first container. */
    public static function container(string $service): string
    {
        return self::NAME . '-' . $service . '-1';
    }
}
