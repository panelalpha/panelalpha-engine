<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\AccountHomeRead;

/**
 * The build context a Dockerfile was written for, told by its own COPY and ADD lines: the first candidate directory
 * holding every source they name. `docker/Dockerfile.oldev` copies `requirements.txt` from the root, so it builds
 * from the root; `markdown/Dockerfile` copying `app.py` beside it builds from its own directory.
 */
final class DockerfileContext
{
    /**
     * @param  string  $dockerfile  absolute path
     * @param  list<string>  $candidates  absolute directories, in preference order
     */
    public static function of(string $dockerfile, array $candidates): ?string
    {
        $contents = AccountHomeRead::contents($dockerfile);
        if (! is_string($contents)) {
            return null;
        }
        $sources = self::copiedSources($contents);
        foreach (array_values(array_unique($candidates)) as $dir) {
            if (self::holdsAll(rtrim($dir, '/'), $sources)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * The local paths COPY and ADD take from the context; a copy from a stage, a URL or a variable is no evidence.
     *
     * @return list<string>
     */
    public static function copiedSources(string $dockerfile): array
    {
        $joined = (string) preg_replace('/\\\\\r?\n/', ' ', $dockerfile);
        $sources = [];
        foreach (preg_split('/\R/', $joined) ?: [] as $line) {
            if (preg_match('/^\s*(?:COPY|ADD)\s+(.+)$/i', $line, $m) === 1) {
                array_push($sources, ...self::instructionSources(trim($m[1])));
            }
        }

        return array_values(array_unique($sources));
    }

    /** @return list<string> */
    private static function instructionSources(string $arguments): array
    {
        $words = self::arguments($arguments);
        $paths = [];
        foreach ($words as $word) {
            if (str_starts_with($word, '--from=')) {
                return [];
            }
            if (! str_starts_with($word, '--')) {
                $paths[] = $word;
            }
        }
        array_pop($paths);

        return array_values(array_filter($paths, self::isLocalSource(...)));
    }

    /** @return list<string> */
    private static function arguments(string $arguments): array
    {
        if (str_starts_with($arguments, '[')) {
            $decoded = json_decode($arguments, true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return preg_split('/\s+/', $arguments) ?: [];
    }

    private static function isLocalSource(string $path): bool
    {
        return $path !== '' && ! str_contains($path, '$') && ! str_contains($path, '://') && ! str_starts_with($path, '<<');
    }

    /** @param  list<string>  $sources */
    private static function holdsAll(string $dir, array $sources): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        foreach ($sources as $source) {
            $path = $dir.'/'.ltrim($source, '/');
            $exists = strpbrk($source, '*?[') === false ? file_exists($path) : (glob($path) ?: []) !== [];
            if (! $exists) {
                return false;
            }
        }

        return true;
    }
}
