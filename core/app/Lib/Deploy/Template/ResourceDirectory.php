<?php

namespace App\Lib\Deploy\Template;

use App\Lib\Deploy\Platform\PlatformRegistry;

/**
 * Where the shipped deploy resources live: `core/resources/deploy/`, resolved relative to this file rather
 * than through `resource_path()` so generators are testable unbooted, the bargain {@see PlatformRegistry} makes.
 */
final class ResourceDirectory
{
    private const RELATIVE_ROOT = '/../../../../resources/deploy';

    public static function templates(): string
    {
        return self::path('templates');
    }

    public static function assets(): string
    {
        return self::path('assets');
    }

    private static function path(string $name): string
    {
        $path = __DIR__.self::RELATIVE_ROOT.'/'.$name;

        return realpath($path) ?: $path;
    }
}
