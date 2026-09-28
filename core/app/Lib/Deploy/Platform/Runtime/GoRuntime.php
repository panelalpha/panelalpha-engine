<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Go, versioned by the `go` directive in go.mod.
 */
final class GoRuntime implements Runtime
{
    /**
     * Minors the engine is willing to run, oldest first; compiled-in fallback
     * for the catalogue's `versions`.
     */
    public const MINORS = ['1.21', '1.22', '1.23', '1.24', '1.25', '1.26'];

    public const DEFAULT_MINOR = '1.22';

    public const IMAGE_VARIANT = 'alpine';

    /**
     * Const expression, so it cannot read the catalogue; `defaultImage()`
     * honours a configured tag.
     */
    public const IMAGE = 'golang:' . self::DEFAULT_MINOR . '-' . self::IMAGE_VARIANT;

    /**
     * The minors a project may resolve to, oldest first.
     *
     * @return list<string>
     */
    public static function minors(): array
    {
        $configured = RuntimeImageCatalog::versions('go');

        return $configured === [] ? self::MINORS : $configured;
    }

    public static function defaultMinor(): string
    {
        return RuntimeImageCatalog::defaultVersion('go') ?? self::DEFAULT_MINOR;
    }

    public static function defaultImage(): string
    {
        return self::imageTag(self::defaultMinor());
    }

    public static function imageTag(string $minor): string
    {
        $spec = RuntimeImageCatalog::spec('go', $minor);

        return $spec?->from ?? 'golang:' . $minor . '-' . self::IMAGE_VARIANT;
    }

    public function id(): string
    {
        return 'go';
    }

    public function resolve(ProjectContext $context): ?Requirement
    {
        $gomod = $context->contents('go.mod');
        if ($gomod === null) {
            return null;
        }

        if (preg_match('/^go\s+(\d+\.\d+)/m', $gomod, $matches) !== 1) {
            return new Requirement('go', self::defaultMinor(), '', 'go.mod (no go directive)');
        }

        $wanted = $matches[1];
        foreach (self::minors() as $minor) {
            if (version_compare($minor, $wanted, '>=')) {
                return new Requirement('go', $minor, $wanted, 'go.mod go directive');
            }
        }

        // Newer than anything listed: honour it. An older toolchain fails the
        // build outright.
        return new Requirement('go', $wanted, $wanted, 'go.mod go directive');
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * `go build -o app .` writes a non-executable library archive (mode 644)
     * when the root is not `package main`, and the container restart-loops
     * behind a 502. Resolve the package first, then refuse a non-runnable app.
     */
    public static function buildCommand(string $projectDir, ?string $sourceUrl = null): string
    {
        [$package, $ambiguous] = self::resolveMainPackage($projectDir, $sourceUrl);

        // Guessed among several entrypoints: name them so the user can pick.
        $note = $ambiguous === [] ? '' : 'echo ' . escapeshellarg(
            'PANELALPHA: several Go main packages (' . implode(', ', $ambiguous) . '); building '
            . $package . '. Set the build command to choose another.'
        ) . ' && ';

        // git is only for modules fetched from VCS instead of GOPROXY, and
        // apk add needs root: a hard install fails the chain with `ERROR:
        // Unable to open log: Permission denied`. Best effort.
        return $note . '{ apk add --no-cache git || true; }'
            . ' && go build -o app ' . $package
            . ' && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }';
    }

    /**
     * Import path of the package to build: the repo root when it is `package
     * main`, else the entrypoint named after the module or repository, else the
     * only one, else the first under cmd/, least nested, alphabetical. Falls
     * back to "."; buildCommand() then fails on the executable guard.
     */
    public static function mainPackage(string $projectDir, ?string $sourceUrl = null): string
    {
        return self::resolveMainPackage($projectDir, $sourceUrl)[0];
    }

    /** Directories holding helpers, tests or samples, never the program. */
    private const NOT_ENTRYPOINTS = '/^(vendor|internal|tools|hack|scripts|docs|test.*|example.*)$/i';

    /**
     * The package, plus the candidates it was guessed from when step 4 decided.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function resolveMainPackage(string $projectDir, ?string $sourceUrl): array
    {
        $projectDir = rtrim($projectDir, '/');
        $candidates = self::mainDirectories($projectDir);
        if ($candidates === [] || in_array('.', $candidates, true)) {
            return ['.', []];
        }

        $entrypoints = self::entrypoints($candidates);
        $names = self::programNames($projectDir, $sourceUrl);

        foreach ($entrypoints as $dir) {
            if (in_array(strtolower(basename($dir)), $names, true)) {
                return ['./' . $dir, []];
            }
        }

        // `weed` in module `seaweedfs`: shortened name, trusted only if unique.
        $contained = array_values(array_filter($entrypoints, static function (string $dir) use ($names): bool {
            $base = strtolower(basename($dir));
            foreach ($names as $name) {
                if (strlen($base) >= 3 && str_contains($name, $base)) {
                    return true;
                }
            }

            return false;
        }));
        if (count($contained) === 1) {
            return ['./' . $contained[0], []];
        }
        if (count($entrypoints) === 1) {
            return ['./' . $entrypoints[0], []];
        }

        $seen = $entrypoints !== [] ? $entrypoints : $candidates;

        return ['./' . self::conventionalPick($candidates, $names), count($seen) > 1 ? $seen : []];
    }

    /**
     * Candidates at `cmd/<name>` or `<name>`, minus helper directories; cmd/ first.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    private static function entrypoints(array $candidates): array
    {
        $found = array_values(array_filter($candidates, static function (string $dir): bool {
            return preg_match('#^(?:cmd/)?([^/]+)$#', $dir, $m) === 1
                && preg_match(self::NOT_ENTRYPOINTS, $m[1]) !== 1;
        }));
        usort($found, static function (string $a, string $b): int {
            $byCmd = (int) !str_starts_with($a, 'cmd/') <=> (int) !str_starts_with($b, 'cmd/');

            return $byCmd !== 0 ? $byCmd : strcmp($a, $b);
        });

        return $found;
    }

    /**
     * Last resort: prefer cmd/, then a name match, then least nested,
     * alphabetical.
     *
     * @param list<string> $candidates
     * @param list<string> $names
     */
    private static function conventionalPick(array $candidates, array $names): string
    {
        $underCmd = array_values(array_filter(
            $candidates,
            static fn (string $dir): bool => preg_match('#(^|/)cmd/#', $dir) === 1
        ));
        $pool = $underCmd !== [] ? $underCmd : $candidates;
        // `ds-dev` beside `ds-host` (Dropserver): a development helper is not
        // what gets served, and being shorter used to make it the pick.
        $serving = array_values(array_filter(
            $pool,
            static fn (string $dir): bool => !self::isHelper(basename($dir))
        ));
        if ($serving !== []) {
            $pool = $serving;
        }

        foreach ($pool as $dir) {
            if (in_array(strtolower(basename($dir)), $names, true)) {
                return $dir;
            }
        }

        usort($pool, static function (string $a, string $b): int {
            $byDepth = substr_count($a, '/') <=> substr_count($b, '/');
            if ($byDepth !== 0) {
                return $byDepth;
            }
            $byLength = strlen($a) <=> strlen($b);

            return $byLength !== 0 ? $byLength : strcmp($a, $b);
        });

        return $pool[0];
    }

    /** Words that mark a program as a helper: `ds-dev`, `api-mock`, `schema-gen`. */
    private const HELPER_WORDS = [
        'dev', 'devel', 'debug', 'mock', 'fake', 'gen', 'generate', 'generator', 'codegen',
        'bench', 'benchmark', 'example', 'demo', 'test', 'testing', 'tool', 'tools', 'lint',
    ];

    private static function isHelper(string $name): bool
    {
        $words = preg_split('/[-_.]/', strtolower($name)) ?: [];

        return array_intersect($words, self::HELPER_WORDS) !== [];
    }

    /**
     * Directories holding a `package main` file, relative to the project root.
     *
     * @return list<string>
     */
    private static function mainDirectories(string $projectDir): array
    {
        if (!is_dir($projectDir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($projectDir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    $name = $file->getFilename();
                    if ($file->isDir()) {
                        return !in_array($name, ['vendor', 'testdata', 'node_modules'], true)
                            && !str_starts_with($name, '.')
                            && !str_starts_with($name, '_');
                    }

                    return str_ends_with($name, '.go') && !str_ends_with($name, '_test.go');
                }
            ),
            // An unreadable directory is skipped, not fatal (#191).
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $contents = @file_get_contents($file->getPathname());
            if (!is_string($contents) || preg_match('/^package\s+main\s*$/m', $contents) !== 1) {
                continue;
            }
            if (preg_match('#^//(go:build|\s*\+build)\s+ignore\b#m', $contents) === 1) {
                continue;
            }
            $dir = substr(dirname($file->getPathname()), strlen($projectDir));
            $dir = trim($dir, '/');
            $dir = $dir === '' ? '.' : $dir;
            // An empty main is a go:generate stub (Traefik's root).
            $stub = preg_match('/^func\s+main\s*\(\s*\)\s*\{\s*\}/m', $contents) === 1;
            $found[$dir] = ($found[$dir] ?? true) && !$stub;
        }

        return array_keys(array_filter($found));
    }

    /**
     * Lowercase names the program likely carries: the module's last segment
     * (`/vN` dropped) and the repository name.
     *
     * @return list<string>
     */
    private static function programNames(string $projectDir, ?string $sourceUrl): array
    {
        $names = [];
        $contents = @file_get_contents($projectDir . '/go.mod');
        if (is_string($contents) && preg_match('/^module\s+(\S+)/m', $contents, $m) === 1) {
            $names[] = basename((string) preg_replace('#/v\d+$#', '', trim($m[1], "\"'")));
        }
        if ($sourceUrl !== null && $sourceUrl !== '') {
            $names[] = (string) preg_replace('/\.git$/', '', basename(rtrim($sourceUrl, '/')));
        }

        return array_values(array_unique(array_filter(array_map('strtolower', $names))));
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('go', self::defaultMinor(), '', 'engine default');
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return self::minors();
    }
}
