<?php

namespace App\Lib\Deploy\Env;

use Symfony\Component\Yaml\Yaml;

/**
 * The `env_file:` paths a compose file expects to exist, which Compose V2 refuses to start without.
 * Only paths inside the project count.
 */
final class ComposeEnvFiles
{
    /**
     * @return list<string> project-relative paths, deduplicated
     */
    public static function declaredIn(string $raw): array
    {
        $parsed = self::parse($raw);

        return $parsed === null ? [] : self::pathsIn($parsed);
    }

    /**
     * @param  array<string, mixed>  $parsed  a decoded compose file
     * @return list<string>
     */
    public static function pathsIn(array $parsed): array
    {
        $services = $parsed['services'] ?? null;
        if (! is_array($services)) {
            return [];
        }

        $paths = [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }
            foreach (self::entriesOf($service['env_file'] ?? null) as $entry) {
                $relative = self::projectRelative($entry);
                if ($relative !== null) {
                    $paths[$relative] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * Add $overridesFile as the last `env_file` of every service already loading `.env` (ADR-0001 D3).
     * Idempotent: an entry already there is moved to the end. A service that loads it in place of `.env` (a recipe's
     * app service, {@see addLast()}) keeps it as it is.
     *
     * @param  array<string, mixed>  $compose  a decoded compose file
     * @return array{0: array<string, mixed>, 1: list<string>} the compose file, and the services that load it
     */
    public static function attach(array $compose, string $overridesFile): array
    {
        if (! is_array($compose['services'] ?? null)) {
            return [$compose, []];
        }

        $changed = [];
        foreach ($compose['services'] as $name => &$service) {
            if (! is_array($service)) {
                continue;
            }
            if (! self::loadsProjectEnv($service)) {
                if (self::loads($service, $overridesFile)) {
                    $changed[] = (string) $name;
                }

                continue;
            }
            [$detached] = self::detach(['services' => ['s' => $service]], $overridesFile);
            $service = $detached['services']['s'];
            $entries = is_array($service['env_file']) ? array_values($service['env_file']) : [$service['env_file']];
            $entries[] = $overridesFile;
            $service['env_file'] = $entries;
            $changed[] = (string) $name;
        }
        unset($service);

        return [$compose, $changed];
    }

    /**
     * Remove every `env_file` entry naming $overridesFile.
     *
     * @param  array<string, mixed>  $compose  a decoded compose file
     * @return array{0: array<string, mixed>, 1: bool} the compose file, and whether anything was removed
     */
    public static function detach(array $compose, string $overridesFile): array
    {
        if (! is_array($compose['services'] ?? null)) {
            return [$compose, false];
        }

        $removed = false;
        foreach ($compose['services'] as &$service) {
            if (! is_array($service) || ! isset($service['env_file'])) {
                continue;
            }
            $entries = is_array($service['env_file']) ? array_values($service['env_file']) : [$service['env_file']];
            $kept = array_values(array_filter($entries, static function ($entry) use ($overridesFile): bool {
                $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;

                return ! is_string($path) || self::projectRelative($path) !== $overridesFile;
            }));
            if (count($kept) === count($entries)) {
                continue;
            }
            $removed = true;
            if ($kept === []) {
                unset($service['env_file']);
            } else {
                $service['env_file'] = $kept;
            }
        }
        unset($service);

        return [$compose, $removed];
    }

    /**
     * Add $envFile to the `env_file:` of $services: after the files the project ships, so a value there wins over
     * them, and before the first file from outside the project, a recipe's stored values, which a template's blank
     * must not replace.
     *
     * @param  array<string, mixed>  $compose  a decoded compose file
     * @param  list<string>  $services
     * @return array{0: array<string, mixed>, 1: list<string>} the compose file, and the services changed
     */
    public static function addTo(array $compose, array $services, string $envFile): array
    {
        $changed = [];
        foreach ($services as $name) {
            $service = $compose['services'][$name] ?? null;
            if (! is_array($service) || self::loads($service, $envFile)) {
                continue;
            }
            $declared = $service['env_file'] ?? null;
            $entries = is_array($declared) ? array_values($declared) : ($declared === null ? [] : [$declared]);
            $at = self::firstOutsideProject($entries) ?? count($entries);
            array_splice($entries, $at, 0, [$envFile]);
            $compose['services'][$name]['env_file'] = $entries;
            $changed[] = $name;
        }

        return [$compose, $changed];
    }

    /**
     * Add $envFile as the last `env_file` of $services, in the long form with `required: false`: a file the engine
     * writes only when it has values, which then win over everything the service loads before it.
     *
     * @param  array<string, mixed>  $compose  a decoded compose file
     * @param  list<string>  $services
     * @return array{0: array<string, mixed>, 1: list<string>} the compose file, and the services changed
     */
    public static function addLast(array $compose, array $services, string $envFile): array
    {
        $changed = [];
        foreach ($services as $name) {
            $service = $compose['services'][$name] ?? null;
            if (! is_array($service) || self::loads($service, $envFile)) {
                continue;
            }
            $declared = $service['env_file'] ?? null;
            $entries = is_array($declared) ? array_values($declared) : ($declared === null ? [] : [$declared]);
            $entries[] = ['path' => $envFile, 'required' => false];
            $compose['services'][$name]['env_file'] = $entries;
            $changed[] = $name;
        }

        return [$compose, $changed];
    }

    /**
     * The services loading $envFile and, after it, some file other than $overridesFile, which wins over `.env`.
     *
     * @param  array<string, mixed>  $compose  a decoded compose file
     * @return list<string>
     */
    public static function outrankingEnv(array $compose, string $envFile, string $overridesFile): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $outranked = [];
        foreach ($services as $name => $service) {
            if (is_array($service) && self::loadsAfter($service, $envFile, $overridesFile)) {
                $outranked[] = (string) $name;
            }
        }

        return $outranked;
    }

    /**
     * Whether the service loads $envFile and, after it, a file other than $overridesFile.
     *
     * @param  array<string, mixed>  $service
     */
    private static function loadsAfter(array $service, string $envFile, string $overridesFile): bool
    {
        $seen = false;
        foreach (self::entriesOf($service['env_file'] ?? null) as $entry) {
            $relative = self::projectRelative($entry);
            if ($seen && $relative !== $overridesFile && $relative !== $envFile) {
                return true;
            }
            $seen = $seen || $relative === $envFile;
        }

        return false;
    }

    /**
     * @param  list<mixed>  $entries  `env_file:` entries
     */
    private static function firstOutsideProject(array $entries): ?int
    {
        foreach ($entries as $i => $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            if (is_string($path) && self::leavesProject($path)) {
                return $i;
            }
        }

        return null;
    }

    private static function leavesProject(string $raw): bool
    {
        $path = str_replace('\\', '/', trim($raw));

        return str_starts_with($path, '/') || in_array('..', explode('/', $path), true);
    }

    /**
     * Whether one of the service's `env_file:` entries is the project's own `.env`.
     *
     * @param  array<string, mixed>  $service
     */
    public static function loadsProjectEnv(array $service): bool
    {
        return self::loads($service, '.env');
    }

    /**
     * Whether one of the service's `env_file:` entries names $relative.
     *
     * @param  array<string, mixed>  $service
     */
    private static function loads(array $service, string $relative): bool
    {
        foreach (self::entriesOf($service['env_file'] ?? null) as $entry) {
            if (self::projectRelative($entry) === $relative) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parse(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $parsed = Yaml::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * `env_file` is a string, a list of strings, or a list of `{path: …}`.
     *
     * @param  mixed  $envFile
     * @return list<string>
     */
    private static function entriesOf($envFile): array
    {
        if (is_string($envFile)) {
            return [$envFile];
        }
        if (! is_array($envFile)) {
            return [];
        }

        $paths = [];
        foreach ($envFile as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** An interpolation's default is the file compose will use, substituted before the guards so they still apply. */
    private static function projectRelative(string $raw): ?string
    {
        $path = str_replace('\\', '/', trim($raw));

        if (str_contains($path, '${')) {
            $path = self::substituteDefaults($path);
            if ($path === null) {
                return null;
            }
        }

        if ($path === '' || str_starts_with($path, '/')) {
            return null;
        }
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    /**
     * `${VAR:-default}` and `${VAR-default}` collapse to `default`; null when there is no single answer.
     * The body is matched without a brace, so a nested reference survives the pass and is refused.
     */
    private static function substituteDefaults(string $path): ?string
    {
        $count = 0;
        $substituted = preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-|-)([^}]*)\}/',
            static function (array $m) use (&$count): string {
                $count++;

                return $m[2];
            },
            $path
        );

        if ($substituted === null || $count !== 1) {
            return null;
        }

        return str_contains($substituted, '${') ? null : $substituted;
    }
}
