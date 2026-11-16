<?php

namespace App\Lib\Deploy\Compose;

/**
 * Where a compose `build:` points on disk, as {@see ComposeFileInspector} checks it.
 */
final class ComposeBuildDockerfile
{
    /**
     * The Dockerfile a `build:` uses, project-relative and absolute, or null when there is no
     * on-disk file to check (inline Dockerfile, git/HTTP context).
     *
     * @param  array<string, mixed>|string  $build
     * @return array{relative: string, absolute: string}|null
     */
    public static function resolve(string $projectDir, array|string $build): ?array
    {
        $paths = self::buildContextAndDockerfile($build);
        if ($paths === null || self::isUncheckableBuildPath(...$paths)) {
            return null;
        }
        [$context, $dockerfile] = $paths;
        if (str_starts_with($dockerfile, '/')) {
            return [
                'relative' => $dockerfile,
                'absolute' => $dockerfile,
            ];
        }

        $relative = self::dockerfileInContext($context, $dockerfile);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return [
            'relative' => $relative,
            'absolute' => rtrim($projectDir, '/').'/'.ltrim($relative, '/'),
        ];
    }

    /**
     * A `build:`'s context and Dockerfile with compose's defaults applied, or null for an inline one.
     *
     * @param  array<string, mixed>|string  $build
     * @return array{0: string, 1: string}|null
     */
    private static function buildContextAndDockerfile(array|string $build): ?array
    {
        if (is_string($build)) {
            $context = $build;
            $dockerfile = 'Dockerfile';
        } else {
            if (isset($build['dockerfile_inline'])) {
                return null;
            }
            $context = $build['context'] ?? '.';
            if (! is_string($context) || $context === '') {
                $context = '.';
            }
            $dockerfile = $build['dockerfile'] ?? 'Dockerfile';
            if (! is_string($dockerfile) || $dockerfile === '') {
                $dockerfile = 'Dockerfile';
            }
        }

        return [str_replace('\\', '/', trim($context)), str_replace('\\', '/', trim($dockerfile))];
    }

    /** Empty, a git/HTTP context, or a path climbing out with `..`: nothing on disk to check. */
    private static function isUncheckableBuildPath(string $context, string $dockerfile): bool
    {
        return $context === '' || $dockerfile === ''
            || preg_match('#^(https?://|git@|ssh://)#i', $context) === 1
            || str_contains($context, '..') || str_contains($dockerfile, '..');
    }

    private static function dockerfileInContext(string $context, string $dockerfile): string
    {
        $contextRel = self::stripDotSlash($context);
        $fileRel = self::stripDotSlash($dockerfile);
        if ($contextRel === '' || $contextRel === '.') {
            return $fileRel;
        }

        return rtrim($contextRel, '/').'/'.ltrim($fileRel, '/');
    }

    private static function stripDotSlash(string $path): string
    {
        if (str_starts_with($path, './')) {
            return substr($path, 2);
        }

        return $path;
    }
}
