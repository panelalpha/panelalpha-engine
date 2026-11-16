<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Frontends a Go program embeds but the checkout lacks (`pattern dist: no matching files found`); one
 * {@see frontendFor()} cannot match (a downloaded binary, a build driven elsewhere) is left to fail as before.
 */
final class GoEmbeddedFrontends
{
    private const MAX_GO_FILES = 3000;

    private const SKIP_DIRS = ['node_modules', 'vendor', 'testdata'];

    /**
     * @param  (\Closure(string): void)|null  $note  told in a sentence when the scan stops at the cap
     * @return list<array{dir: string, embed: string}> frontend dir relative to
     *                                                 the project ('' for the root), and `<file>: <pattern>` that needs it
     */
    public static function missing(string $projectDir, ?\Closure $note = null): array
    {
        $root = rtrim($projectDir, '/');
        $found = [];
        foreach (self::goFiles($root, $note) as $relative) {
            $source = AccountHomeRead::contents($root.'/'.$relative);
            if (! is_string($source) || ! str_contains($source, '//go:embed')) {
                continue;
            }
            $found = self::withMissingEmbeds($found, $root, $relative, $source);
        }

        return array_values($found);
    }

    /**
     * {@see missing()} with what to run for each: the Node image, the install
     * for its package manager and lockfile, and its `build` script.
     *
     * @param  (\Closure(string): void)|null  $note  told in a sentence when the scan stops at the cap
     * @return list<array{dir: string, embed: string, image: string, install: string, build: string}>
     */
    public static function plan(string $projectDir, ?\Closure $note = null): array
    {
        $plan = [];
        foreach (self::missing($projectDir, $note) as $frontend) {
            $abs = rtrim($projectDir, '/').($frontend['dir'] === '' ? '' : '/'.$frontend['dir']);
            $package = json_decode((string) AccountHomeRead::contents($abs.'/package.json'), true);
            $package = is_array($package) ? $package : [];
            $files = ProjectContext::listRootFiles($abs);
            $pm = JsPackageManager::detectPackageManager($files, $package);
            $install = JsPackageManager::installCommand($pm, $files, $package, $abs);
            $plan[] = $frontend + [
                'image' => HostNodeBuild::compilerImage($install, Images::nodeImage($abs, $package), $pm),
                'install' => $install,
                'build' => JsPackageManager::scriptCommand($pm, 'build'),
            ];
        }

        return $plan;
    }

    private static function exists(string $root, string $goDir, string $pattern): bool
    {
        $path = $root.'/'.($goDir === '' ? '' : $goDir.'/').$pattern;
        if (strpbrk($pattern, '*?[') !== false) {
            return (glob($path) ?: []) !== [];
        }

        return file_exists($path);
    }

    /**
     * The directory of a package.json with a `build` script, beside the missing pattern or in the
     * embedding package's own directory.
     */
    private static function frontendFor(string $root, string $goDir, string $pattern): ?string
    {
        if (strpbrk($pattern, '*?[') !== false || str_contains($pattern, '..')) {
            return null;
        }
        $target = trim(($goDir === '' ? '' : $goDir.'/').$pattern, '/');
        $parent = dirname($target) === '.' ? '' : dirname($target);
        foreach (array_unique([$parent, $goDir]) as $dir) {
            $package = json_decode((string) AccountHomeRead::contents($root.'/'.($dir === '' ? '' : $dir.'/').'package.json'), true);
            if (is_array($package) && is_string($package['scripts']['build'] ?? null) && trim($package['scripts']['build']) !== '') {
                return $dir;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{dir: string, embed: string}>  $found
     * @return array<string, array{dir: string, embed: string}>
     */
    private static function withMissingEmbeds(array $found, string $root, string $relative, string $source): array
    {
        $goDir = dirname($relative) === '.' ? '' : dirname($relative);
        preg_match_all('/^\s*\/\/go:embed\s+(.+)$/m', $source, $lines);
        foreach ($lines[1] as $line) {
            preg_match_all('/"([^"]+)"|`([^`]+)`|(\S+)/', $line, $tokens, PREG_SET_ORDER);
            foreach ($tokens as $token) {
                $pattern = preg_replace('/^all:/', '', $token[1] ?: ($token[2] ?? '') ?: ($token[3] ?? ''));
                if (! is_string($pattern) || $pattern === '' || self::exists($root, $goDir, $pattern)) {
                    continue;
                }
                $dir = self::frontendFor($root, $goDir, $pattern);
                if ($dir !== null && ! isset($found[$dir])) {
                    $found[$dir] = ['dir' => $dir, 'embed' => $relative.': '.$pattern];
                }
            }
        }

        return $found;
    }

    /**
     * At most MAX_GO_FILES paths in name order; past the cap the least nested are kept, then the first by name,
     * so the filesystem's listing order never decides which files are read.
     *
     * @param  (\Closure(string): void)|null  $note
     * @return list<string> paths relative to $root
     */
    private static function goFiles(string $root, ?\Closure $note = null): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (self::goFileIterator($root) as $file) {
            $files[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
        }
        usort($files, self::shallowestFirst(...));
        if (count($files) > self::MAX_GO_FILES && $note !== null) {
            $note(sprintf(
                'Looked for embedded frontends in %d of %d Go files, the least nested first; '
                .'a frontend embedded by one of the others is not built before the Go build.',
                self::MAX_GO_FILES,
                count($files)
            ));
        }
        $kept = array_slice($files, 0, self::MAX_GO_FILES);
        sort($kept);

        return $kept;
    }

    private static function shallowestFirst(string $a, string $b): int
    {
        return substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b);
    }

    /** Non-test .go files outside skipped, hidden and `_` directories; an unreadable directory is skipped. */
    private static function goFileIterator(string $root): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        $name = $file->getFilename();

                        return ! in_array($name, self::SKIP_DIRS, true) && ! str_starts_with($name, '.')
                            && ! str_starts_with($name, '_');
                    }

                    return str_ends_with($file->getFilename(), '.go') && ! str_ends_with($file->getFilename(), '_test.go');
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
    }
}
