<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Frontends a Go program embeds but the checkout does not contain.
 *
 * A server that serves its web UI with `//go:embed dist` compiles only after
 * that directory is built, usually by `npm run build` beside it. The checkout
 * has the sources, not the output, so the Go build stopped on
 * `pattern dist: no matching files found` (Dropserver, zincsearch, nginx-ui).
 *
 * A missing pattern is matched to the package.json in the directory that
 * would hold it (`frontend/` for `frontend/dist`) or in the embedding
 * package's own directory, and only when that package.json has a `build`
 * script. Anything else (a downloaded binary, a build driven from elsewhere)
 * is left to fail as before.
 */
final class GoEmbeddedFrontends
{
    private const MAX_GO_FILES = 3000;

    private const SKIP_DIRS = ['node_modules', 'vendor', 'testdata'];

    /**
     * @return list<array{dir: string, embed: string}> frontend dir relative to
     *         the project ('' for the root), and `<file>: <pattern>` that needs it
     */
    public static function missing(string $projectDir): array
    {
        $root = rtrim($projectDir, '/');
        $found = [];
        foreach (self::goFiles($root) as $relative) {
            $source = @file_get_contents($root . '/' . $relative);
            if (!is_string($source) || !str_contains($source, '//go:embed')) {
                continue;
            }
            $goDir = dirname($relative) === '.' ? '' : dirname($relative);
            preg_match_all('/^\s*\/\/go:embed\s+(.+)$/m', $source, $lines);
            foreach ($lines[1] as $line) {
                preg_match_all('/"([^"]+)"|`([^`]+)`|(\S+)/', $line, $tokens, PREG_SET_ORDER);
                foreach ($tokens as $token) {
                    $pattern = preg_replace('/^all:/', '', $token[1] ?: ($token[2] ?? '') ?: ($token[3] ?? ''));
                    if (!is_string($pattern) || $pattern === '' || self::exists($root, $goDir, $pattern)) {
                        continue;
                    }
                    $dir = self::frontendFor($root, $goDir, $pattern);
                    if ($dir !== null && !isset($found[$dir])) {
                        $found[$dir] = ['dir' => $dir, 'embed' => $relative . ': ' . $pattern];
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * {@see missing()} with what to run for each: the Node image, the install
     * for its package manager and lockfile, and its `build` script.
     *
     * @return list<array{dir: string, embed: string, image: string, install: string, build: string}>
     */
    public static function plan(string $projectDir): array
    {
        $plan = [];
        foreach (self::missing($projectDir) as $frontend) {
            $abs = rtrim($projectDir, '/') . ($frontend['dir'] === '' ? '' : '/' . $frontend['dir']);
            $package = json_decode((string) @file_get_contents($abs . '/package.json'), true);
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
        $path = $root . '/' . ($goDir === '' ? '' : $goDir . '/') . $pattern;
        if (strpbrk($pattern, '*?[') !== false) {
            return (glob($path) ?: []) !== [];
        }

        return file_exists($path);
    }

    private static function frontendFor(string $root, string $goDir, string $pattern): ?string
    {
        if (strpbrk($pattern, '*?[') !== false || str_contains($pattern, '..')) {
            return null;
        }
        $target = trim(($goDir === '' ? '' : $goDir . '/') . $pattern, '/');
        $parent = dirname($target) === '.' ? '' : dirname($target);
        foreach (array_unique([$parent, $goDir]) as $dir) {
            $package = json_decode((string) @file_get_contents($root . '/' . ($dir === '' ? '' : $dir . '/') . 'package.json'), true);
            if (is_array($package) && is_string($package['scripts']['build'] ?? null) && trim($package['scripts']['build']) !== '') {
                return $dir;
            }
        }

        return null;
    }

    /** @return list<string> paths relative to $root */
    private static function goFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        $name = $file->getFilename();

                        return !in_array($name, self::SKIP_DIRS, true) && !str_starts_with($name, '.')
                            && !str_starts_with($name, '_');
                    }

                    return str_ends_with($file->getFilename(), '.go') && !str_ends_with($file->getFilename(), '_test.go');
                }
            )
        );
        $files = [];
        foreach ($iterator as $file) {
            $files[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
            if (count($files) >= self::MAX_GO_FILES) {
                break;
            }
        }
        sort($files);

        return $files;
    }
}
