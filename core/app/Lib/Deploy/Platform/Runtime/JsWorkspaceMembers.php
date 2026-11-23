<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;

/**
 * The member directories a JS workspace declares in package.json `workspaces` or pnpm-workspace.yaml, from literal
 * paths and one-level `dir/*` globs only, relative to the workspace root.
 */
final class JsWorkspaceMembers
{
    private const MAX_MEMBERS = 64;

    /**
     * @param  array<string, mixed>  $package  the root package.json, decoded
     * @return list<string>
     */
    public static function directories(array $package, string $root): array
    {
        $root = rtrim($root, '/');
        $dirs = [];
        foreach ([...JsPackageManager::workspaces($package), ...self::pnpmWorkspacePatterns($root)] as $pattern) {
            array_push($dirs, ...self::expand($pattern, $root));
        }

        return array_slice(array_values(array_unique($dirs)), 0, self::MAX_MEMBERS);
    }

    /** @return list<string> */
    private static function expand(string $pattern, string $root): array
    {
        if (preg_match('#^((?:[\w.@-]+/)*[\w.@-]+)/\*$#', $pattern, $m) === 1) {
            $children = @scandir($root.'/'.$m[1]) ?: [];

            return array_values(array_map(
                static fn (string $child): string => $m[1].'/'.$child,
                array_filter($children, static fn (string $child): bool => $child[0] !== '.')
            ));
        }

        return preg_match('#^(?:[\w.@-]+/)*[\w.@-]+$#', $pattern) === 1 && ! str_contains($pattern, '..')
            ? [$pattern]
            : [];
    }

    /** @return list<string> */
    private static function pnpmWorkspacePatterns(string $root): array
    {
        $raw = AccountHomeRead::contents($root.'/pnpm-workspace.yaml');
        if (! is_string($raw) || preg_match('/^packages:\s*\n((?:[ \t]+-.*\n?|[ \t]*#.*\n?|[ \t]*\n)+)/m', $raw, $m) !== 1) {
            return [];
        }
        preg_match_all('/^[ \t]+-[ \t]*[\'"]?([^\'"#\n]+?)[\'"]?[ \t]*(?:#.*)?$/m', $m[1], $items);

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item, " \t/"), $items[1]),
            static fn (string $item): bool => $item !== '' && ! str_starts_with($item, '!')
        ));
    }
}
