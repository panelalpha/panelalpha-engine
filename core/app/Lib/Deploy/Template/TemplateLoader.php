<?php

namespace App\Lib\Deploy\Template;

/**
 * Reads the files under `resources/deploy/`, once per process.
 *
 * Stubs are templates with placeholders; assets are shipped verbatim — the
 * nginx config, the PHP router, the standalone Node server. Both are files
 * on disk for the same reason: an editor highlights them, a reviewer diffs
 * them, and neither has to be un-escaped out of a heredoc first.
 *
 * No Laravel dependencies — unit-testable.
 */
final class TemplateLoader
{
    private const STUB_EXTENSION = '.stub';

    /** @var array<string, string> absolute path => contents */
    private static array $cache = [];

    public static function stub(string $name): string
    {
        return self::contents(ResourceDirectory::templates().'/'.$name.self::STUB_EXTENSION);
    }

    public static function asset(string $name): string
    {
        return self::contents(ResourceDirectory::assets().'/'.$name);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function contents(string $path): string
    {
        return self::$cache[$path] ??= self::read($path);
    }

    private static function read(string $path): string
    {
        if (! is_file($path)) {
            throw TemplateException::missingFile($path);
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            throw TemplateException::unreadableFile($path);
        }

        return $contents;
    }
}
