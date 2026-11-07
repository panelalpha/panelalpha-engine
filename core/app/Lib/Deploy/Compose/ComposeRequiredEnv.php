<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\SidecarDialects;

/**
 * Secrets a project's compose file needs from `.env` and nobody supplies, where {@see ComposePlaceholders}
 * cannot reach (a `command:`, an `include:`d file, an `env_file:`). Seeded, so a redeploy keeps them.
 */
final class ComposeRequiredEnv
{
    /** `${NAME:?msg}` / `${NAME?msg}`; `$${...}` is compose's escape, not a reference. */
    private const REQUIRED_REFERENCE = '/(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*):?\?([^}$]*)(\})?/';

    /** A whole value that is one reference: `${NAME}`, `${NAME:-default}`, `${NAME:?msg}`. */
    private const WHOLE_REFERENCE = '/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?])([^}]*))?\}$/';

    private const MAX_INCLUDE_DEPTH = 3;

    /**
     * @param  list<array{dir: string, yaml: string}>  $files  the compose file and
     *                                                         everything it includes, each with its directory relative to the project
     * @param  array<string, string>  $env  what `.env` (and the account) already set
     * @param  string|(\Closure(): ?string)|null  $publicUrl  the account's address, for a
     *                                                        required variable naming the app's own URL; a closure is asked only when one does
     * @return array<string, string> NAME => value, for the keys `.env` must gain
     */
    public static function missing(array $files, array $env, string $seed, string|\Closure|null $publicUrl = null): array
    {
        [$needed, $base64, $urls] = self::neededNames($files, $env);

        $out = [];
        $publicUrl = $urls !== [] && $publicUrl instanceof \Closure ? $publicUrl() : $publicUrl;
        if (is_string($publicUrl) && $publicUrl !== '') {
            foreach (array_keys($urls) as $name) {
                $out[(string) $name] = $publicUrl;
            }
        }

        return self::withGeneratedValues($out, $needed, $base64, $env, $seed);
    }

    /**
     * @param  list<array{dir: string, yaml: string}>  $files
     * @param  array<string, string>  $env
     * @return array{0: array<string, int|false>, 1: array<string, int>, 2: array<string, true>}
     */
    private static function neededNames(array $files, array $env): array
    {
        $needed = [];
        $base64 = [];
        $urls = [];
        foreach ($files as $file) {
            foreach (self::requiredOwnUrlNames($file['yaml']) as $name => $_) {
                if (trim((string) ($env[$name] ?? '')) === '') {
                    $urls[$name] = true;
                }
            }
            foreach (self::requiredSecretNames($file['yaml']) as $name => [$length, $bytes]) {
                $needed[$name] = max((int) ($needed[$name] ?? 0), $length);
                $base64[$name] = max($base64[$name] ?? 0, $bytes);
            }
            foreach (self::datastorePasswordNames($file['yaml'], $file['dir'], $env) as $name) {
                $needed[$name] ??= 0;
            }
            foreach (self::datastoreInitNames($file['yaml']) as $name) {
                $needed[$name] ??= false;
            }
        }

        return [$needed, $base64, $urls];
    }

    /**
     * A user or database name gets the engine's usual `app`, as a kept sidecar does
     * ({@see SidecarCredentials::pinSidecarCredentials()}).
     *
     * @param  array<string, string>  $out
     * @param  array<string, int|false>  $needed
     * @param  array<string, int>  $base64
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private static function withGeneratedValues(array $out, array $needed, array $base64, array $env, string $seed): array
    {
        foreach ($needed as $name => $length) {
            if (trim((string) ($env[$name] ?? '')) !== '') {
                continue;
            }
            $out[(string) $name] = $length !== false || ComposePlaceholders::isSecretKey((string) $name)
                ? ComposePlaceholders::requiredSecretValue((string) $name, $seed, (int) $length, $base64[$name] ?? 0)
                : 'app';
        }

        return $out;
    }

    /**
     * `include:` paths of a compose file, relative to the project, for the caller to hand to {@see missing()}.
     *
     * @return list<string>
     */
    public static function includedPaths(string $yaml, string $dir): array
    {
        $parsed = ComposeYaml::parse($yaml);
        $include = is_array($parsed) ? ($parsed['include'] ?? null) : null;
        if (! is_array($include)) {
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
     * Reads a compose file and whatever it includes, through a reader that answers null for anything unreadable.
     *
     * @param  callable(string): ?string  $read  relative path => contents
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
            if (! is_string($yaml) || $yaml === '') {
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
     * Required variables anywhere in the file that name a credential; a message ComposePlaceholders
     * cannot read whole gives no hint, as there.
     *
     * @return array<string, array{0: int, 1: int}> name => the hex length and base64 bytes its message asks for, or 0
     */
    private static function requiredSecretNames(string $yaml): array
    {
        preg_match_all(self::REQUIRED_REFERENCE, $yaml, $m, PREG_SET_ORDER);
        $names = [];
        foreach ($m as $ref) {
            if (ComposePlaceholders::isSecretKey($ref[1])) {
                $message = ($ref[3] ?? '') === '}' ? $ref[2] : '';
                $names[$ref[1]] = [
                    max($names[$ref[1]][0] ?? 0, ComposePlaceholders::hintedLength($message)),
                    max($names[$ref[1]][1] ?? 0, ComposePlaceholders::hintedBase64Bytes($message)),
                ];
            }
        }

        return $names;
    }

    /**
     * Required variables naming the app's own address (`${EGMA_BASE_URL:?}`).
     *
     * @return array<string, true>
     */
    private static function requiredOwnUrlNames(string $yaml): array
    {
        preg_match_all(self::REQUIRED_REFERENCE, $yaml, $m, PREG_SET_ORDER);
        $names = [];
        foreach ($m as $ref) {
            if (ComposePlaceholders::isRequiredOwnUrlKey($ref[1])) {
                $names[$ref[1]] = true;
            }
        }

        return $names;
    }

    /**
     * A database with a blank superuser password refuses its first start; the empty one of its
     * `environment:` variable or the project's `.env` (through `env_file:`) is the name to generate.
     *
     * @param  array<string, string>  $env
     * @return list<string>
     */
    private static function datastorePasswordNames(string $yaml, string $dir, array $env): array
    {
        $services = self::servicesIn($yaml);
        if ($services === null) {
            return [];
        }

        $names = [];
        foreach ($services as $service) {
            if (! is_array($service)) {
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
     * Variables a datastore's other init settings are written as, bare (`POSTGRES_USER=${DB_USER}`), which
     * nothing sets: the database falls back to its default while the app connects as nobody.
     *
     * @return list<string>
     */
    private static function datastoreInitNames(string $yaml): array
    {
        $services = self::servicesIn($yaml);
        if ($services === null) {
            return [];
        }

        $names = [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }
            $engine = SidecarDialects::canonical(ComposeService::familyOf((string) ($service['image'] ?? '')));
            if ($engine === null) {
                continue;
            }
            array_push($names, ...self::bareInitNames($service, $engine));
        }

        return $names;
    }

    /**
     * Only the bare form: a default or a `:?` is the author's own choice.
     *
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    private static function bareInitNames(array $service, string $engine): array
    {
        $names = [];
        $passwords = SidecarDialects::passwordVariablesFor($engine)['passwords'] ?? [];
        $declared = self::environmentOf($service['environment'] ?? null);
        foreach (array_diff(SidecarDialects::initVariablesFor($engine), $passwords) as $key) {
            if (preg_match(self::WHOLE_REFERENCE, trim((string) ($declared[$key] ?? '')), $m) === 1
                && ($m[2] ?? '') === ''
            ) {
                $names[] = $m[1];
            }
        }

        return $names;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function servicesIn(string $yaml): ?array
    {
        $parsed = ComposeYaml::parse($yaml);
        $services = is_array($parsed) ? ($parsed['services'] ?? null) : null;

        return is_array($services) ? $services : null;
    }

    /**
     * A waiver (POSTGRES_HOST_AUTH_METHOD, a `*_FILE`) is the author's own way in; with nothing declared,
     * only a `.env` the service reads can set it.
     *
     * @param  array<string, mixed>  $service
     * @param  array{passwords: list<string>, waivers: list<string>}  $vocabulary
     * @param  array<string, string>  $env
     */
    private static function passwordSource(array $service, array $vocabulary, string $dir, array $env): ?string
    {
        $declared = self::environmentOf($service['environment'] ?? null);
        $fromEnvFile = self::loadsProjectEnv($service['env_file'] ?? null, $dir);
        $setInEnvFile = static fn (string $key): bool => $fromEnvFile && trim((string) ($env[$key] ?? '')) !== '';

        foreach ($vocabulary['waivers'] as $key) {
            if (array_key_exists($key, $declared) || $setInEnvFile($key)) {
                return null;
            }
        }

        foreach ($vocabulary['passwords'] as $key) {
            if (! array_key_exists($key, $declared)) {
                if ($setInEnvFile($key)) {
                    return null;
                }

                continue;
            }

            return self::unsetReference($declared[$key], $env);
        }

        return $fromEnvFile ? ($vocabulary['passwords'][0] ?? null) : null;
    }

    /**
     * The variable a password is written as when nothing gives it a value; a literal, or a value too
     * involved to second-guess, is left as written.
     *
     * @param  array<string, string>  $env
     */
    private static function unsetReference(string $value, array $env): ?string
    {
        if (preg_match(self::WHOLE_REFERENCE, trim((string) $value), $m) !== 1) {
            return null;
        }
        $operator = $m[2] ?? '';
        $default = $operator === ':-' || $operator === '-' ? trim((string) ($m[3] ?? '')) : '';
        if ($default !== '' || trim((string) ($env[$m[1]] ?? '')) !== '') {
            return null;
        }

        return $m[1];
    }

    /**
     * @return array<string, string>
     */
    private static function environmentOf(mixed $environment): array
    {
        if (! is_array($environment)) {
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
        foreach (explode('/', ($dir === '' ? '' : $dir.'/').$path) as $segment) {
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
