<?php

namespace App\Lib\Deploy\Compose;

/**
 * Files a compose service binds from the checkout that the repository leaves out and ships an example of beside it
 * (`config.yaml` with `config.example.yaml`). Docker would create a directory in the missing file's place, and the
 * mount over the image's file then fails with "not a directory".
 *
 * No Laravel dependencies — unit-testable.
 */
final class MissingBindFiles
{
    private const FILE_NAME = '/\.(?:json|env|conf|cfg|ya?ml|toml|ini|xml|php|js|py|properties)$|^caddyfile$/i';

    /** How a repository names the example of `<stem>.<ext>`, `%s` the stem and `%e` the extension. */
    private const EXAMPLE_NAMES = [
        '%s.%e.example', '%s.%e.sample', '%s.%e.dist', '%s.%e.template', '%s.%e.default',
        '%s.example.%e', '%s.sample.%e', '%s.dist.%e', '%s.template.%e', '%s.default.%e',
        '%s-example.%e', '%s_example.%e', '%s-sample.%e', 'example.%s.%e',
    ];

    /**
     * @param  array<string, mixed>  $compose  the run file, whose relative paths are relative to $projectDir
     * @param  callable(string): bool  $exists  whether a path exists in the checkout
     * @param  array<string, list<?string>|string>  $env  what each variable may be set to ({@see ComposeInterpolation})
     * @return array<string, string> missing absolute path => the absolute path of its example
     */
    public static function examples(array $compose, string $projectDir, callable $exists, array $env = []): array
    {
        $projectDir = rtrim($projectDir, '/');
        $services = $compose['services'] ?? null;
        if (! is_array($services) || $projectDir === '') {
            return [];
        }

        $found = [];
        foreach ($services as $service) {
            foreach (is_array($service) && is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
                $path = self::fileSource($volume, $projectDir, $env);
                if ($path === null || isset($found[$path]) || $exists($path)) {
                    continue;
                }
                $example = self::exampleOf($path, $exists);
                if ($example !== null) {
                    $found[$path] = $example;
                }
            }
        }

        return $found;
    }

    /**
     * @param  callable(string): bool  $exists
     */
    private static function exampleOf(string $path, callable $exists): ?string
    {
        $dir = dirname($path);
        $base = basename($path);
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0) {
            foreach (['.example', '.sample', '.dist', '.template', '.default'] as $suffix) {
                if ($exists($dir.'/'.$base.$suffix)) {
                    return $dir.'/'.$base.$suffix;
                }
            }

            return null;
        }
        $stem = substr($base, 0, $dot);
        $extension = substr($base, $dot + 1);
        foreach (self::EXAMPLE_NAMES as $pattern) {
            $candidate = $dir.'/'.str_replace(['%s', '%e'], [$stem, $extension], $pattern);
            if ($exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The bind's source, absolute and strictly inside the checkout, when it reads as a file: a named volume, a
     * directory or a path outside the checkout is not one.
     *
     * @param  array<string, list<?string>|string>  $env
     */
    private static function fileSource(mixed $volume, string $projectDir, array $env): ?string
    {
        $source = self::bindSource($volume);
        if ($source === null) {
            return null;
        }
        $candidates = ComposeInterpolation::candidates($source, $env);
        if ($candidates === null || count($candidates) !== 1) {
            return null;
        }
        $source = trim($candidates[0]);
        if (! str_starts_with($source, './') && ! str_starts_with($source, '/')) {
            return null;
        }
        $path = self::normalised(str_starts_with($source, '/') ? $source : $projectDir.'/'.$source);

        return $path !== null && str_starts_with($path, $projectDir.'/') && preg_match(self::FILE_NAME, basename($path)) === 1
            ? $path
            : null;
    }

    /** `.` and `..` resolved without touching the disk; null when `..` climbs past the root. */
    private static function normalised(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    private static function bindSource(mixed $volume): ?string
    {
        if (is_string($volume)) {
            $parts = explode(':', trim($volume));

            return count($parts) >= 2 ? $parts[0] : null;
        }

        return is_array($volume) && strtolower((string) ($volume['type'] ?? '')) === 'bind' && is_string($volume['source'] ?? null)
            ? $volume['source']
            : null;
    }
}
