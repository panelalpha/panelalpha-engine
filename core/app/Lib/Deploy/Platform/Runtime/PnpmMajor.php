<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;

/**
 * The pnpm major for a project without `packageManager`: the lockfile's, else the newest every `engines.pnpm`
 * (root and workspace members) allows, else the default; pnpm refuses another major's lockfile or an unmet engine.
 */
final class PnpmMajor
{
    public const DEFAULT = 10;

    /** Newest first: an open range such as `>=8` gets the newest major it allows. */
    private const CANDIDATES = [10, 9, 8, 7, 6];

    /** lockfileVersion major => the pnpm major that writes it; pnpm 10 still writes 9.0. */
    private const BY_LOCKFILE_MAJOR = [5 => 7, 6 => 8, 9 => self::DEFAULT];

    private const LOCKFILE_HEAD_BYTES = 256;

    private const MAX_MEMBERS = 64;

    /**
     * @param  array<string, mixed>  $package  the root package.json, decoded
     */
    public static function forProject(array $package, ?string $projectDir): int
    {
        $lockfileMajor = $projectDir === null ? null : self::fromLockfile($projectDir);
        $constraints = self::engineConstraints($package, $projectDir);
        foreach (self::preferred($lockfileMajor) as $major) {
            if (self::satisfiesAll($major, $constraints)) {
                return $major;
            }
        }

        return $lockfileMajor ?? self::DEFAULT;
    }

    /** The pnpm major that wrote `pnpm-lock.yaml`, or null when there is none or its version is unknown. */
    public static function fromLockfile(string $projectDir): ?int
    {
        $head = AccountHomeRead::contents(rtrim($projectDir, '/').'/pnpm-lock.yaml', self::LOCKFILE_HEAD_BYTES);
        if (! is_string($head) || preg_match('/^lockfileVersion:\s*[\'"]?(\d+)/m', $head, $m) !== 1) {
            return null;
        }
        $lockfileMajor = (int) $m[1];
        if ($lockfileMajor < 5) {
            return null;
        }

        return self::BY_LOCKFILE_MAJOR[$lockfileMajor] ?? null;
    }

    /** Whether pnpm $major satisfies an `engines.pnpm` range; an unreadable range allows everything. */
    public static function satisfies(int $major, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return true;
        }
        foreach (preg_split('/\s*\|\|\s*/', $range) ?: [] as $alternative) {
            if (self::satisfiesAlternative($major, $alternative)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    private static function preferred(?int $lockfileMajor): array
    {
        return array_values(array_unique(array_merge(
            $lockfileMajor === null ? [] : [$lockfileMajor],
            self::CANDIDATES
        )));
    }

    /** @param  list<string>  $constraints */
    private static function satisfiesAll(int $major, array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if (! self::satisfies($major, $constraint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private static function engineConstraints(array $package, ?string $projectDir): array
    {
        $constraints = [];
        $packages = [$package, ...($projectDir === null ? [] : self::memberPackages($package, $projectDir))];
        foreach ($packages as $manifest) {
            $range = $manifest['engines']['pnpm'] ?? null;
            if (is_string($range) && trim($range) !== '') {
                $constraints[] = $range;
            }
        }

        return $constraints;
    }

    /**
     * The workspace members' package.json, from literal paths and one-level `dir/*` globs only.
     *
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private static function memberPackages(array $package, string $projectDir): array
    {
        $root = rtrim($projectDir, '/');
        $members = [];
        foreach (self::memberDirectories($package, $root) as $dir) {
            $decoded = json_decode((string) AccountHomeRead::contents($root.'/'.$dir.'/package.json'), true);
            if (is_array($decoded)) {
                $members[] = $decoded;
            }
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private static function memberDirectories(array $package, string $root): array
    {
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

    private static function satisfiesAlternative(int $major, string $alternative): bool
    {
        $alternative = trim($alternative);
        if (preg_match('/^v?(\d+)\S*\s+-\s+v?(\d+)/', $alternative, $m) === 1) {
            return $major >= (int) $m[1] && $major <= (int) $m[2];
        }
        foreach (preg_split('/\s+/', $alternative) ?: [] as $comparator) {
            if (! self::satisfiesComparator($major, $comparator)) {
                return false;
            }
        }

        return true;
    }

    private static function satisfiesComparator(int $major, string $comparator): bool
    {
        if (preg_match('/^(\^|~|>=|<=|>|<|=)?\s*v?(\d+|x|\*)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?/i', $comparator, $m) !== 1) {
            return true;
        }
        if (! ctype_digit($m[2])) {
            return true;
        }
        $bound = (int) $m[2];
        $rest = (int) ($m[3] ?? 0) + (int) ($m[4] ?? 0);
        $exactMajorOnly = ($m[3] ?? '') === '';

        return match ($m[1]) {
            '>=' => $major >= $bound,
            '>' => $exactMajorOnly ? $major > $bound : $major >= $bound,
            '<=' => $major <= $bound,
            '<' => $rest === 0 ? $major < $bound : $major <= $bound,
            default => $major === $bound,
        };
    }
}
