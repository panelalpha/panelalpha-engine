<?php

namespace App\Lib\Deploy\Compose;

/**
 * Checkout directories a long-syntax bind asks Docker not to create (`bind: {create_host_path: false}`), so a
 * fresh checkout that lacks one fails `up`. A source that reads as a file is left out: files are the project's.
 *
 * No Laravel dependencies — unit-testable.
 */
final class UncreatedBindDirectories
{
    private const FILE_NAME = '/(?:^|\.)(?:pem|crt|cert|key|pub|json|env|conf|cfg|ya?ml|toml|ini|xml|txt|log|lock|sock|db|sqlite3?'
        .'|properties|php|js|py|sh|htpasswd)$/i';

    /**
     * @param  array<string, mixed>  $compose  the run file, whose relative paths are relative to $projectDir
     * @param  array<string, list<?string>|string>  $env  what each variable may be set to ({@see ComposeInterpolation})
     * @return list<string> absolute paths strictly inside $projectDir
     */
    public static function of(array $compose, string $projectDir, array $env = []): array
    {
        $projectDir = rtrim($projectDir, '/');
        $services = $compose['services'] ?? null;
        if (! is_array($services) || $projectDir === '') {
            return [];
        }

        $found = [];
        foreach ($services as $service) {
            foreach (is_array($service) && is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
                foreach (self::directories($volume, $projectDir, $env) as $path) {
                    $found[$path] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param  array<string, list<?string>|string>  $env
     * @return list<string>
     */
    private static function directories(mixed $volume, string $projectDir, array $env): array
    {
        if (! self::refusesHostPath($volume) || self::readsAsFile((string) ($volume['target'] ?? ''))) {
            return [];
        }
        $sources = ComposeInterpolation::candidates(trim((string) ($volume['source'] ?? '')), $env) ?? [];

        return array_values(array_filter(array_map(
            static fn (string $source): ?string => self::insideProject($source, $projectDir),
            $sources,
        )));
    }

    private static function refusesHostPath(mixed $volume): bool
    {
        return is_array($volume)
            && strtolower((string) ($volume['type'] ?? '')) === 'bind'
            && is_array($volume['bind'] ?? null)
            && array_key_exists('create_host_path', $volume['bind'])
            && filter_var($volume['bind']['create_host_path'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;
    }

    private static function readsAsFile(string $path): bool
    {
        return preg_match(self::FILE_NAME, basename(rtrim(trim($path), '/'))) === 1;
    }

    private static function insideProject(string $source, string $projectDir): ?string
    {
        $source = trim($source);
        if ($source === '' || str_starts_with($source, '~') || self::readsAsFile($source)) {
            return null;
        }
        $absolute = str_starts_with($source, '/') ? $source : $projectDir.'/'.$source;
        $path = WritableProjectBinds::normalised($absolute);

        return $path !== null && str_starts_with($path, $projectDir.'/') ? $path : null;
    }
}
