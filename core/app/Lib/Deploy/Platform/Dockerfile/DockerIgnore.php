<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Template\TemplateLoader;

/**
 * The `.dockerignore` the engine writes when a project has none; a project's
 * own is left as the customer wrote it.
 *
 * `.git` must not enter the build context: two clones of one repository give an
 * identical worktree and different `.git` bytes, so `COPY . .` hashed
 * differently on every deploy and rebuilt every layer below it.
 */
final class DockerIgnore
{
    public const FILENAME = '.dockerignore';

    public static function contents(): string
    {
        return TemplateLoader::asset('dockerignore');
    }

    /**
     * Does the project's own `.dockerignore` keep $path out of the build
     * context? A `COPY` of such a path fails the build with `"/<path>": not
     * found` even though the file is in the checkout: zigbee2mqtt ships an
     * `.npmrc` and lists it here.
     *
     * Docker's rules: one pattern per line, `#` comments, `!` re-includes,
     * the last match wins, `*` stays within a path segment and `**` spans
     * any number of them, and excluding a directory excludes what is in it.
     */
    public static function excludes(string $projectDir, string $path): bool
    {
        $file = rtrim($projectDir, '/') . '/' . self::FILENAME;
        if ($projectDir === '' || !is_file($file)) {
            return false;
        }
        $path = trim($path, '/');
        $excluded = false;
        foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $negate = $line[0] === '!';
            $pattern = trim(ltrim($negate ? substr($line, 1) : $line), '/');
            $pattern = (string) preg_replace('#^(\./)+#', '', $pattern);
            if ($pattern !== '' && self::matches($pattern, $path)) {
                $excluded = !$negate;
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

        return preg_match('#^' . $regex . '(?:/.*)?$#', $path) === 1;
    }
}
