<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\AccountHomeRead;

/**
 * What a next.config sets for every build, read without running it: a key inside an object that is a side of `?:`,
 * `&&` or `||` applies only when its condition holds, one nested under another key is not Next's own, and a commented
 * one never applies.
 */
final class NextExportConfig
{
    private const EXPORT = '/(?:\boutput|([\'"])output\1)\s*:\s*[\'"]export[\'"]/';

    private const COMMENTS = '#/\*.*?\*/|(?<![:\'"\w])//[^\n]*#s';

    private const BASE_PATH = '/(?:\bbasePath|([\'"])basePath\1)\s*:\s*[\'"](\/[A-Za-z0-9._~\/-]*)[\'"]/';

    private const BRANCH_OPERATORS = ['?', ':', '&', '|'];

    /** The names Next.js loads, in its order; `next.config.example.js` and the like are not the config. */
    private const FILES = ['next.config.js', 'next.config.mjs', 'next.config.ts', 'next.config.mts', 'next.config.cjs'];

    /** The config Next.js would load from $dir; null when it has none. */
    public static function read(string $dir): ?string
    {
        foreach (self::FILES as $name) {
            $config = AccountHomeRead::contents(rtrim($dir, '/').'/'.$name);
            if (is_string($config)) {
                return $config;
            }
        }

        return null;
    }

    public static function exportsUnconditionally(string $config): bool
    {
        return self::unconditionalMatches(self::EXPORT, $config) !== [];
    }

    /** A literal `basePath` that applies to every build, without its trailing slash; null for none or `/`. */
    public static function basePath(string $config): ?string
    {
        $path = rtrim((string) (self::unconditionalMatches(self::BASE_PATH, $config)[0][2] ?? ''), '/');

        return $path === '' || str_contains($path, '//') || str_contains($path, '/.') ? null : $path;
    }

    /** @return list<array<int, string>> the matches of $pattern outside comments, conditional objects and nested keys */
    private static function unconditionalMatches(string $pattern, string $config): array
    {
        $code = (string) preg_replace(self::COMMENTS, '', $config);
        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches as $match) {
            if (! self::inBranch($code, $match[0][1])) {
                $found[] = array_map(static fn (array $group): string => $group[0], $match);
            }
        }

        return $found;
    }

    private static function inBranch(string $code, int $offset): bool
    {
        $open = self::enclosingBrace($code, $offset);
        if ($open === null) {
            return false;
        }
        $before = rtrim(substr($code, 0, $open), " \t\r\n(");

        return $before !== '' && in_array(substr($before, -1), self::BRANCH_OPERATORS, true);
    }

    private static function enclosingBrace(string $code, int $offset): ?int
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            if ($code[$i] === '}') {
                $depth++;
            } elseif ($code[$i] === '{') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            }
        }

        return null;
    }
}
