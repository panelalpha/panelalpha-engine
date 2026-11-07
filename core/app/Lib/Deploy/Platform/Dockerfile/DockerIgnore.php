<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Template\TemplateLoader;

/**
 * The `.dockerignore` the engine writes when a project has none; it keeps `.git` out because two
 * clones differ in `.git` bytes, so `COPY . .` would rebuild every layer below it on each deploy.
 */
final class DockerIgnore
{
    public const FILENAME = '.dockerignore';

    public static function contents(): string
    {
        return TemplateLoader::asset('dockerignore');
    }

    /**
     * Does the project's own `.dockerignore` keep $path out of the build context, so a `COPY` of it
     * fails? Docker's rules: `!` re-includes, the last match wins, `**` spans path segments.
     */
    public static function excludes(string $projectDir, string $path): bool
    {
        $file = rtrim($projectDir, '/').'/'.self::FILENAME;
        if ($projectDir === '' || ! is_file($file)) {
            return false;
        }
        $path = trim($path, '/');
        $excluded = false;
        foreach (preg_split('/\R/', (string) AccountHomeRead::contents($file)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $negate = $line[0] === '!';
            $pattern = trim(ltrim($negate ? substr($line, 1) : $line), '/');
            $pattern = (string) preg_replace('#^(\./)+#', '', $pattern);
            if ($pattern !== '' && self::matches($pattern, $path)) {
                $excluded = ! $negate;
            }
        }

        return $excluded;
    }

    /** $pattern matches $path itself or one of its parent directories. */
    private static function matches(string $pattern, string $path): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $regex .= '.*';
                $i++;
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return preg_match('#^'.$regex.'(?:/.*)?$#', $path) === 1;
    }
}
