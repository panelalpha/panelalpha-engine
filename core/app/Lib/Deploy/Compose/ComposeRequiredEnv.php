<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\SidecarDialects;

/**
 * Secrets a project's compose file needs from `.env` and nobody supplies.
 *
 * {@see ComposePlaceholders} rewrites values inside `environment:`, which
 * cannot reach `${VAR:?}` in a `command:`, in a URL, or in an `include:`d
 * file, and cannot supply a variable a datastore reads through `env_file:`.
 * Compose interpolates all of those from the project's `.env`, so that is
 * where the values go. Seeded like the rest, so a redeploy keeps them.
 *
 * No Laravel dependencies — unit-testable.
 */
final class ComposeRequiredEnv
{
    /** `${NAME:?msg}` / `${NAME?msg}`; `$${...}` is compose's escape, not a reference. */
    private const REQUIRED_REFERENCE = '/(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*):?\?/';

    /** A whole value that is one reference: `${NAME}`, `${NAME:-default}`, `${NAME:?msg}`. */
    private const WHOLE_REFERENCE = '/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?])([^}]*))?\}$/';

    private const MAX_INCLUDE_DEPTH = 3;

    /**
     * @param list<array{dir: string, yaml: string}> $files the compose file and
     *        everything it includes, each with its directory relative to the project
     * @param array<string, string> $env what `.env` (and the account) already set
     * @return array<string, string> NAME => value, for the keys `.env` must gain
     */
    public static function missing(array $files, array $env, string $seed): array
    {
        $needed = [];
        foreach ($files as $file) {
            foreach (self::requiredSecretNames($file['yaml']) as $name) {
                $needed[$name] = true;
            }
            foreach (self::datastorePasswordNames($file['yaml'], $file['dir'], $env) as $name) {
                $needed[$name] = true;
            }
        }

        $out = [];
        foreach (array_keys($needed) as $name) {
            if (trim((string) ($env[$name] ?? '')) === '') {
                $out[(string) $name] = ComposePlaceholders::generatedSecret((string) $name, $seed);
            }
        }

        return $out;
    }

    /**
     * `include:` paths of a compose file, relative to the project, so the
     * caller can read them and hand them back to {@see missing()}.
     *
     * @return list<string>
     */
    public static function includedPaths(string $yaml, string $dir): array
    {
        $parsed = ComposeYaml::parse($yaml);
        $include = is_array($parsed) ? ($parsed['include'] ?? null) : null;
        if (!is_array($include)) {
            return [];
        }

        $paths = [];
        foreach ($include as $entry) {
            $candidates = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            foreach ((array) $candidates as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $resolved = self::relativeTo($dir, trim($path));
                    if ($resolved !== null) {
                        $paths[] = $resolved;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * Reads a compose file and whatever it includes, through a reader that
     * answers null for anything missing or unreadable.
     *
     * @param callable(string): ?string $read relative path => contents
     * @return list<array{dir: string, yaml: string}>
     */
    public static function collect(string $relativePath, callable $read): array
    {
        $files = [];
        $queue = [[$relativePath, 0]];
        $seen = [];
        while ($queue !== []) {
            [$path, $depth] = array_shift($queue);
            if (isset($seen[$path]) || $depth > self::MAX_INCLUDE_DEPTH) {
                continue;
            }
            $seen[$path] = true;
            $yaml = $read($path);
            if (!is_string($yaml) || $yaml === '') {
                continue;
            }
            $dir = dirname($path);
            $dir = $dir === '.' ? '' : $dir;
            $files[] = ['dir' => $dir, 'yaml' => $yaml];
            foreach (self::includedPaths($yaml, $dir) as $included) {
                $queue[] = [$included, $depth + 1];
            }
        }

        return $files;
    }

    /**
     * Required variables anywhere in the file that name a credential. A
     * required hostname or port is not something to invent.
     *
     * @return list<string>
     */
    private static function requiredSecretNames(string $yaml): array
    {
        preg_match_all(self::REQUIRED_REFERENCE, $yaml, $m);
        $names = [];
        foreach (array_unique($m[1] ?? []) as $name) {
            if (ComposePlaceholders::isSecretKey($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * A database whose superuser password is left blank refuses its first
     * start (postgres: "superuser password is not specified"). The password
     * comes from `environment:` through a variable, or from the project's
     * `.env` through `env_file:`; whichever of the two is empty is the name
     * to generate.
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    private static function datastorePasswordNames(string $yaml, string $dir, array $env): array
    {
        $parsed = ComposeYaml::parse($yaml);
        $services = is_array($parsed) ? ($parsed['services'] ?? null) : null;
        if (!is_array($services)) {
            return [];
        }

        $names = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $engine = SidecarDialects::canonical(ComposeService::familyOf((string) ($service['image'] ?? '')));
            $vocabulary = $engine === null ? null : SidecarDialects::passwordVariablesFor($engine);
            if ($vocabulary === null || $vocabulary['passwords'] === []) {
                continue;
            }
            $name = self::passwordSource($service, $vocabulary, $dir, $env);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $service
     * @param array{passwords: list<string>, waivers: list<string>} $vocabulary
     * @param array<string, string> $env
     */
    private static function passwordSource(array $service, array $vocabulary, string $dir, array $env): ?string
    {
        $declared = self::environmentOf($service['environment'] ?? null);
        $fromEnvFile = self::loadsProjectEnv($service['env_file'] ?? null, $dir);
        $setInEnvFile = static fn (string $key): bool => $fromEnvFile && trim((string) ($env[$key] ?? '')) !== '';

        // POSTGRES_HOST_AUTH_METHOD, MYSQL_RANDOM_ROOT_PASSWORD, a *_FILE: the
        // author chose another way in, and that is theirs to keep.
        foreach ($vocabulary['waivers'] as $key) {
            if (array_key_exists($key, $declared) || $setInEnvFile($key)) {
                return null;
            }
        }

        foreach ($vocabulary['passwords'] as $key) {
            if (!array_key_exists($key, $declared)) {
                if ($setInEnvFile($key)) {
                    return null;
                }
                continue;
            }
            // A literal, or a value too involved to second-guess, is left as written.
            if (preg_match(self::WHOLE_REFERENCE, trim((string) $declared[$key]), $m) !== 1) {
                return null;
            }
            $operator = $m[2] ?? '';
            $default = $operator === ':-' || $operator === '-' ? trim((string) ($m[3] ?? '')) : '';
            if ($default !== '' || trim((string) ($env[$m[1]] ?? '')) !== '') {
                return null;
            }

            return $m[1];
        }

        // Nothing declares it: only `.env` can, and only when the service reads it.
        return $fromEnvFile ? ($vocabulary['passwords'][0] ?? null) : null;
    }

    /**
     * @return array<string, string>
     */
    private static function environmentOf(mixed $environment): array
    {
        if (!is_array($environment)) {
            return [];
        }
        $map = [];
        foreach ($environment as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && str_contains($value, '=')) {
                    [$k, $v] = explode('=', $value, 2);
                    $map[trim($k)] = $v;
                }
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $map[(string) $key] = (string) $value;
            }
        }

        return $map;
    }

    /** Whether one of the service's `env_file:` entries is the project's own `.env`. */
    private static function loadsProjectEnv(mixed $envFile, string $dir): bool
    {
        $entries = is_array($envFile) ? $envFile : [$envFile];
        foreach ($entries as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            if (is_string($path) && self::relativeTo($dir, trim($path)) === '.env') {
                return true;
            }
        }

        return false;
    }

    /** `../../.env` from `docker/compose` is `.env`; null for anything outside the project. */
    private static function relativeTo(string $dir, string $path): ?string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', ($dir === '' ? '' : $dir . '/') . $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return $parts === [] ? null : implode('/', $parts);
    }
}
