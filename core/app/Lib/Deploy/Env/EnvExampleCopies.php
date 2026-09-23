<?php

namespace App\Lib\Deploy\Env;

use App\Lib\Deploy\Compose\ComposeFileInspector;

/**
 * The `.env` files a checkout is missing and cannot start without.
 *
 * Three sources, in this order of confidence:
 *
 *  1. A `.env.example` beside a directory that has no `.env` — including a
 *     few levels down, because a monorepo's api and web each ship their own.
 *  2. A path the compose file names in `env_file:`. Compose V2 refuses to
 *     start when one is missing, so a destination with no source still gets
 *     an entry with an empty `example`, meaning "create it empty".
 *  3. `.env.local`, for the frameworks that read it (Next.js, and anything
 *     started with `bun --env-file=.env.local`).
 */
final class EnvExampleCopies
{
    private const MAX_DEPTH = 3;

    private const ENV = '.env';

    private const EXAMPLE = '.env.example';

    private const LOCAL = '.env.local';

    private const LOCAL_EXAMPLE = '.env.local.example';

    /**
     * Directories that never hold a project's own configuration, and are big.
     *
     * @var array<string, true>
     */
    private const SKIP_DIRS = [
        '.git' => true,
        '.next' => true,
        '.panelalpha' => true,
        '.turbo' => true,
        '.venv' => true,
        '__pycache__' => true,
        'coverage' => true,
        'dist' => true,
        'node_modules' => true,
        'vendor' => true,
    ];

    /** Files that mention `.env.local` when a framework expects one. */
    private const LOCAL_MARKERS = [
        'package.json',
        'content-collections.ts',
        'next.config.ts',
        'next.config.mjs',
        'next.config.js',
    ];

    /**
     * @return list<array{example: string, dest: string, relative: string}>
     */
    public static function for(string $projectDir): array
    {
        return array_values(self::collect($projectDir, false));
    }

    /**
     * Where every copy {@see for()} makes lands, including the ones already
     * made -- the files a deploy leaves behind as Engine Artifacts when the
     * repository does not track them.
     *
     * @return list<string> project-relative paths of files that exist
     */
    public static function made(string $projectDir): array
    {
        $made = [];
        foreach (self::collect($projectDir, true) as $dest => $copy) {
            if (is_file($dest)) {
                $made[] = $copy['relative'];
            }
        }

        return $made;
    }

    /**
     * @return array<string, array{example: string, dest: string, relative: string}>
     */
    public static function local(string $projectDir): array
    {
        return self::localCopy($projectDir, false);
    }

    /**
     * @return array<string, array{example: string, dest: string, relative: string}>
     */
    private static function collect(string $projectDir, bool $includeMade): array
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '' || !is_dir($projectDir)) {
            return [];
        }

        $copies = [];
        self::collectExamples($projectDir, '', 0, $copies, $includeMade);
        // First writer wins: a sibling example is a better source than the
        // root one, and both beat creating the file empty.
        $copies += self::composeDeclared($projectDir, $includeMade);
        $copies += self::localCopy($projectDir, $includeMade);

        return $copies;
    }

    /**
     * @return array<string, array{example: string, dest: string, relative: string}>
     */
    private static function localCopy(string $projectDir, bool $includeMade): array
    {
        $projectDir = rtrim($projectDir, '/');
        $dest = $projectDir . '/' . self::LOCAL;
        if ($projectDir === '' || !is_dir($projectDir) || (!$includeMade && is_file($dest))) {
            return [];
        }

        $source = self::localSource($projectDir);

        return $source === null ? [] : [$dest => self::copy($source, $dest, self::LOCAL)];
    }

    public static function mentionsEnvLocal(string $projectDir): bool
    {
        $projectDir = rtrim($projectDir, '/');
        foreach (self::LOCAL_MARKERS as $name) {
            // Asked, not attempted. `@` suppresses the warning but not the
            // error handler, so a project with no next.config wrote three
            // ERROR lines into the log on every deploy -- noise that reads
            // like a failure in a file nobody was expected to have.
            $path = $projectDir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (is_string($raw) && str_contains($raw, self::LOCAL)) {
                return true;
            }
        }

        return false;
    }

    private static function localSource(string $projectDir): ?string
    {
        $localExample = $projectDir . '/' . self::LOCAL_EXAMPLE;
        if (is_file($localExample)) {
            return $localExample;
        }
        if (!self::mentionsEnvLocal($projectDir)) {
            return null;
        }

        foreach ([$projectDir . '/' . self::ENV, $projectDir . '/' . self::EXAMPLE] as $source) {
            if (is_file($source)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{example: string, dest: string, relative: string}> $copies
     */
    private static function collectExamples(string $projectDir, string $rel, int $depth, array &$copies, bool $includeMade): void
    {
        $dir = $rel === '' ? $projectDir : $projectDir . '/' . $rel;
        foreach ([self::EXAMPLE => self::ENV, self::LOCAL_EXAMPLE => self::LOCAL] as $example => $target) {
            $source = $dir . '/' . $example;
            $dest = $dir . '/' . $target;
            if (is_file($source) && ($includeMade || !is_file($dest))) {
                $copies[$dest] = self::copy($source, $dest, $rel === '' ? $target : $rel . '/' . $target);
            }
        }

        if ($depth < self::MAX_DEPTH) {
            foreach (self::childDirectories($projectDir, $rel, $dir) as $childRel) {
                self::collectExamples($projectDir, $childRel, $depth + 1, $copies, $includeMade);
            }
        }
    }

    /**
     * @return list<string> project-relative directory paths
     */
    private static function childDirectories(string $projectDir, string $rel, string $dir): array
    {
        $children = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || isset(self::SKIP_DIRS[strtolower($entry)])) {
                continue;
            }
            $childRel = $rel === '' ? $entry : $rel . '/' . $entry;
            if (is_dir($projectDir . '/' . $childRel)) {
                $children[] = $childRel;
            }
        }

        return $children;
    }

    /**
     * @return array<string, array{example: string, dest: string, relative: string}>
     */
    private static function composeDeclared(string $projectDir, bool $includeMade): array
    {
        $raw = self::composeContents($projectDir);
        if ($raw === null) {
            return [];
        }

        $copies = [];
        foreach (ComposeEnvFiles::declaredIn($raw) as $relative) {
            $dest = $projectDir . '/' . $relative;
            if (($includeMade || !is_file($dest)) && is_dir(dirname($dest))) {
                $copies[$dest] = self::copy(self::sourceFor($projectDir, $dest), $dest, $relative);
            }
        }

        return $copies;
    }

    private static function composeContents(string $projectDir): ?string
    {
        $path = ComposeFileInspector::firstIn($projectDir);
        $raw = $path !== null && is_readable($path) ? @file_get_contents($path) : null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /** '' means "create the file empty" — Compose only needs it to exist. */
    private static function sourceFor(string $projectDir, string $dest): string
    {
        $candidates = [
            dirname($dest) . '/' . self::EXAMPLE,
            $projectDir . '/' . self::EXAMPLE,
            $projectDir . '/' . self::ENV,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return array{example: string, dest: string, relative: string}
     */
    private static function copy(string $example, string $dest, string $relative): array
    {
        return ['example' => $example, 'dest' => $dest, 'relative' => $relative];
    }
}
