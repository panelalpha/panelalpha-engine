<?php

namespace App\Lib\Deploy\Env;

/**
 * The names a repository gives the `.env` template it expects copied (`.env.example`, `.env.sample`,
 * `.example.env`); one list, so seeding `.env` and the inspect report agree.
 */
final class EnvTemplates
{
    /** @var list<string> in order of preference */
    public const NAMES = [
        '.env.example',
        '.env.sample',
        '.env.template',
        '.example.env',
        'example.env',
        '.env.dist',
    ];

    /**
     * The first template present.
     *
     * @param  callable(string): bool  $exists  name => whether that file exists
     */
    public static function first(callable $exists): ?string
    {
        foreach (self::NAMES as $name) {
            if ($exists($name)) {
                return $name;
            }
        }

        return null;
    }

    public static function isTemplate(string $path): bool
    {
        return in_array(basename($path), self::NAMES, true);
    }
}
