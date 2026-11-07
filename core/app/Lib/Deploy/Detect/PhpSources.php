<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * PHP source files in a project with no Composer manifest, which php.yaml would not match:
 * root first, then two levels down.
 */
final class PhpSources
{
    /** Directories that hold someone else's PHP, or a copy of the project's. */
    private const SKIPPED = ['vendor', 'node_modules', 'cache', 'tmp', 'temp', 'backup', 'backups'];

    /** Root is depth 0: root, its children and their children. */
    private const MAX_DEPTH = 2;

    /** Bounds the walk on an unexpectedly large tree. */
    private const MAX_DIRECTORIES = 200;

    /** Breadth first: root files answer the common case before any descent. */
    public static function present(string $projectDir): bool
    {
        $projectDir = rtrim($projectDir, '/');
        if (! is_dir($projectDir)) {
            return false;
        }

        $queue = [[$projectDir, 0]];
        $scanned = 0;

        while ($queue !== []) {
            [$dir, $depth] = array_shift($queue);
            if (++$scanned > self::MAX_DIRECTORIES) {
                return false;
            }

            if (self::directoryHoldsPhp($dir, $depth, $queue)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0: string, 1: int}>  $queue */
    private static function directoryHoldsPhp(string $dir, int $depth, array &$queue): bool
    {
        foreach (ProjectContext::entries($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_file($path)) {
                if (str_ends_with(strtolower($entry), '.php')) {
                    return true;
                }

                continue;
            }

            if ($depth < self::MAX_DEPTH
                && is_dir($path)
                && ! in_array(strtolower($entry), self::SKIPPED, true)
            ) {
                $queue[] = [$path, $depth + 1];
            }
        }

        return false;
    }
}
