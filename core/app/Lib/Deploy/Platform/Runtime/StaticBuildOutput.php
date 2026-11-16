<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Where a static build wrote its page when the recipe's predicted output has no index.html: the directories of the
 * index.html files written since the build started, outside dependencies, `public/` and hidden directories, so a
 * source template, a copied public page or a cache is never taken for the site.
 */
final class StaticBuildOutput
{
    /** Left in an output moved to the predicted directory, so the next build clears it first; nginx denies dotfiles. */
    public const RELOCATED_MARKER = StandaloneNodeServe::RELOCATED_MARKER;

    /** Deep enough for `packages/app/build/index.html`, `apps/web/dist/index.html`. */
    private const MAX_DEPTH = 5;

    private const SKIPPED_DIRS = ['node_modules', 'public', 'vendor', 'bower_components'];

    /**
     * The project-relative directories holding an index.html modified at or after $since, the outermost of nested
     * ones, sorted; never the project root.
     *
     * @return list<string>
     */
    public static function writtenSince(string $projectDir, int $since): array
    {
        $root = rtrim($projectDir, '/');
        if (! is_dir($root) || is_link($root)) {
            return [];
        }
        $dirs = [];
        foreach (self::indexFiles($root) as $file) {
            if ($file->isFile() && ! $file->isLink() && $file->getMTime() >= $since) {
                $dirs[] = substr($file->getPath(), strlen($root) + 1);
            }
        }

        return self::outermost(array_values(array_filter($dirs, static fn (string $dir): bool => $dir !== '')));
    }

    /** The marker's path in a relocated output. */
    public static function markerIn(string $outputDir): string
    {
        return rtrim($outputDir, '/').'/'.self::RELOCATED_MARKER;
    }

    /** @return \Iterator<\SplFileInfo> */
    private static function indexFiles(string $root): \Iterator
    {
        $tree = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    $name = $file->getFilename();
                    if ($file->isDir()) {
                        return ! $file->isLink() && ! str_starts_with($name, '.') && ! in_array($name, self::SKIPPED_DIRS, true);
                    }

                    return $name === 'index.html';
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $tree->setMaxDepth(self::MAX_DEPTH);

        return $tree;
    }

    /**
     * @param  list<string>  $dirs
     * @return list<string>
     */
    private static function outermost(array $dirs): array
    {
        sort($dirs, SORT_STRING);

        return array_values(array_filter(
            $dirs,
            static fn (string $dir): bool => ! self::isBelowAnother($dir, $dirs)
        ));
    }

    /** @param  list<string>  $dirs */
    private static function isBelowAnother(string $dir, array $dirs): bool
    {
        foreach ($dirs as $other) {
            if (str_starts_with($dir, $other.'/')) {
                return true;
            }
        }

        return false;
    }
}
