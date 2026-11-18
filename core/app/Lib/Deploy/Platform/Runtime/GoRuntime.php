<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;
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
    public const IMAGE = 'golang:'.self::DEFAULT_MINOR.'-'.self::IMAGE_VARIANT;

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

        return $spec?->from ?? 'golang:'.$minor.'-'.self::IMAGE_VARIANT;
    }

    public function id(): string
    {
        return 'go';
    }

    /** A go directive newer than every listed minor is honoured: an older toolchain fails the build outright. */
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

        return new Requirement('go', $wanted, $wanted, 'go.mod go directive');
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * Builds the resolved package and refuses a non-runnable result: a non-main root builds a mode 644 archive.
     * git (for modules fetched from VCS) is best effort, since `apk add` needs root.
     */
    public static function buildCommand(string $projectDir, ?string $sourceUrl = null): string
    {
        [$package, $ambiguous] = self::resolveMainPackage($projectDir, $sourceUrl);

        return self::ambiguityNote($package, $ambiguous).'{ apk add --no-cache git || true; }'
            .' && go build -o app '.$package
            .' && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }';
    }

    /**
     * Names the entrypoints a guess was made among, so the user can pick another.
     *
     * @param  list<string>  $ambiguous
     */
    private static function ambiguityNote(string $package, array $ambiguous): string
    {
        return $ambiguous === [] ? '' : 'echo '.escapeshellarg(
            'PANELALPHA: several Go main packages ('.implode(', ', $ambiguous).'); building '
            .$package.'. Set the build command to choose another.'
        ).' && ';
    }

    /**
     * The package to build: a `package main` root, else the entrypoint named after the module or repository,
     * else the only one, else the first under cmd/, least nested; "." fails buildCommand()'s executable guard.
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
                return ['./'.$dir, []];
            }
        }

        $contained = self::containedEntrypoints($entrypoints, $names);
        if (count($contained) === 1) {
            return ['./'.$contained[0], []];
        }
        if (count($entrypoints) === 1) {
            return ['./'.$entrypoints[0], []];
        }

        $seen = $entrypoints !== [] ? $entrypoints : $candidates;

        return ['./'.self::conventionalPick($candidates, $names), count($seen) > 1 ? $seen : []];
    }

    /**
     * Entrypoints whose name a program name contains, `weed` in module `seaweedfs`; trusted only when unique.
     *
     * @param  list<string>  $entrypoints
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function containedEntrypoints(array $entrypoints, array $names): array
    {
        return array_values(array_filter($entrypoints, static function (string $dir) use ($names): bool {
            $base = strtolower(basename($dir));
            foreach ($names as $name) {
                if (strlen($base) >= 3 && str_contains($name, $base)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Candidates at `cmd/<name>` or `<name>`, minus helper directories; cmd/ first.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private static function entrypoints(array $candidates): array
    {
        $found = array_values(array_filter($candidates, static function (string $dir): bool {
            return preg_match('#^(?:cmd/)?([^/]+)$#', $dir, $m) === 1
                && preg_match(self::NOT_ENTRYPOINTS, $m[1]) !== 1;
        }));
        usort($found, static function (string $a, string $b): int {
            $byCmd = (int) ! str_starts_with($a, 'cmd/') <=> (int) ! str_starts_with($b, 'cmd/');

            return $byCmd !== 0 ? $byCmd : strcmp($a, $b);
        });

        return $found;
    }

    /**
     * Last resort: prefer cmd/, then a name match, then least nested,
     * alphabetical.
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $names
     */
    private static function conventionalPick(array $candidates, array $names): string
    {
        $underCmd = array_values(array_filter(
            $candidates,
            static fn (string $dir): bool => preg_match('#(^|/)cmd/#', $dir) === 1
        ));
        $pool = $underCmd !== [] ? $underCmd : $candidates;
        $serving = self::servingPrograms($pool);
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

    /**
     * The pool without helpers: Dropserver's `ds-dev` is not what gets served, though shorter than `ds-host`.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private static function servingPrograms(array $pool): array
    {
        return array_values(array_filter(
            $pool,
            static fn (string $dir): bool => ! self::isHelper(basename($dir))
        ));
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
     * Directories holding a `package main` file, relative to the project root, sorted so that the listing
     * order of the filesystem never reaches the build command.
     *
     * @return list<string>
     */
    private static function mainDirectories(string $projectDir): array
    {
        if (! is_dir($projectDir)) {
            return [];
        }

        $found = [];
        $iterator = self::goSourceFiles($projectDir);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $contents = AccountHomeRead::contents($file->getPathname());
            if (! is_string($contents) || preg_match('/^package\s+main\s*$/m', $contents) !== 1) {
                continue;
            }
            if (! self::constraintsHold($contents)) {
                continue;
            }
            $dir = self::relativeDir($projectDir, $file);
            $stub = self::isGenerateStub($contents);
            $found[$dir] = ($found[$dir] ?? true) && ! $stub;
        }
        $directories = array_keys(array_filter($found));
        sort($directories, SORT_STRING);

        return $directories;
    }

    /**
     * Non-test .go files outside vendored, hidden and `_` directories and other modules; an unreadable
     * directory is skipped.
     */
    private static function goSourceFiles(string $projectDir): \RecursiveIteratorIterator
    {
        $workspace = is_file($projectDir.'/go.work');

        return new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($projectDir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($workspace): bool {
                    $name = $file->getFilename();
                    if ($file->isDir()) {
                        return ! in_array($name, ['vendor', 'testdata', 'node_modules'], true)
                            && ! str_starts_with($name, '.')
                            && ! str_starts_with($name, '_')
                            && ($workspace || ! self::isOtherModule($file));
                    }

                    return str_ends_with($name, '.go') && ! str_ends_with($name, '_test.go');
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
    }

    /**
     * A directory with its own go.mod is a module of its own: `go build` from the root refuses it with "main
     * module does not contain package", unless a go.work at the root joins them.
     */
    private static function isOtherModule(\SplFileInfo $dir): bool
    {
        return is_file($dir->getPathname().'/go.mod');
    }

    private static function relativeDir(string $projectDir, \SplFileInfo $file): string
    {
        $dir = substr(dirname($file->getPathname()), strlen($projectDir));
        $dir = trim($dir, '/');

        return $dir === '' ? '.' : $dir;
    }

    /** An empty main is a go:generate stub (Traefik's root). */
    private static function isGenerateStub(string $contents): bool
    {
        return preg_match('/^func\s+main\s*\(\s*\)\s*\{\s*\}/m', $contents) === 1;
    }

    /**
     * Whether a linux, cgo-off build (the golang alpine image) compiles this file per `//go:build` or `// +build`.
     * Custom tags such as `ignore` and `tools` are off, so the tools.go idiom's `package main` is not the program.
     */
    public static function constraintsHold(string $source): bool
    {
        $header = preg_split('/^package\s/m', $source, 2)[0] ?? '';
        if (preg_match('#^//go:build\s+(.+?)\s*$#m', $header, $m) === 1) {
            return self::evaluateConstraint($m[1]) ?? true;
        }
        preg_match_all('#^//\s*\+build\s+(.+?)\s*$#m', $header, $lines);
        foreach ($lines[1] as $line) {
            if (! self::legacyLineHolds($line)) {
                return false;
            }
        }

        return true;
    }

    /** One `// +build` line: space-separated options are ORed, comma-separated terms ANDed. */
    private static function legacyLineHolds(string $line): bool
    {
        $any = false;
        foreach (preg_split('/\s+/', $line) ?: [] as $option) {
            $all = true;
            foreach (explode(',', $option) as $term) {
                $negated = str_starts_with($term, '!');
                $all = $all && (self::tagIsSet(ltrim($term, '!')) !== $negated);
            }
            $any = $any || $all;
        }

        return $any;
    }

    /** Null when the expression cannot be parsed: the file is then counted, as before. */
    private static function evaluateConstraint(string $expression): ?bool
    {
        if (preg_match_all('/\s*(\|\||&&|!|\(|\)|[\w.]+)/A', $expression, $m) === 0
            || implode('', $m[0]) !== $expression
        ) {
            return null;
        }
        $tokens = $m[1];
        $pos = 0;
        $value = self::parseOr($tokens, $pos);

        return $value !== null && $pos === count($tokens) ? $value : null;
    }

    /** @param list<string> $tokens */
    private static function parseOr(array $tokens, int &$pos): ?bool
    {
        $value = self::parseAnd($tokens, $pos);
        while ($value !== null && ($tokens[$pos] ?? null) === '||') {
            $pos++;
            $right = self::parseAnd($tokens, $pos);
            $value = $right === null ? null : ($value || $right);
        }

        return $value;
    }

    /** @param list<string> $tokens */
    private static function parseAnd(array $tokens, int &$pos): ?bool
    {
        $value = self::parseUnary($tokens, $pos);
        while ($value !== null && ($tokens[$pos] ?? null) === '&&') {
            $pos++;
            $right = self::parseUnary($tokens, $pos);
            $value = $right === null ? null : ($value && $right);
        }

        return $value;
    }

    /** @param list<string> $tokens */
    private static function parseUnary(array $tokens, int &$pos): ?bool
    {
        $token = $tokens[$pos++] ?? null;
        if ($token === '!') {
            $value = self::parseUnary($tokens, $pos);

            return $value === null ? null : ! $value;
        }
        if ($token === '(') {
            $value = self::parseOr($tokens, $pos);

            return ($tokens[$pos++] ?? null) === ')' ? $value : null;
        }

        return $token !== null && preg_match('/^[\w.]+$/', $token) === 1 ? self::tagIsSet($token) : null;
    }

    /** Tags a linux build without cgo satisfies; the architecture is the host's. */
    private static function tagIsSet(string $tag): bool
    {
        $arch = match (php_uname('m')) {
            'aarch64', 'arm64' => 'arm64',
            default => 'amd64',
        };

        return in_array($tag, ['linux', 'unix', 'gc', $arch], true)
            || preg_match('/^go1\.\d+$/', $tag) === 1;
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
        $contents = AccountHomeRead::contents($projectDir.'/go.mod');
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
