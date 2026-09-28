<?php

namespace App\Lib\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposePlaceholders;

/**
 * Connection settings for a compose sidecar (database, cache, search engine),
 * derived from the sidecar's own service definition and generated upper-cased
 * under its prefix: `<PREFIX>_HOST`, `_PORT`, `_URL`, `<PREFIX>_*`, `DB_*`.
 */
class SidecarCredentials
{
    /**
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts ports the image declares, when known
     * @param ?SidecarPasswords $passwords fills a password nobody set; null
     *        keeps the legacy `app`
     */
    public static function pinSidecarCredentials(
        string $name,
        array $service,
        array $observedPorts = [],
        ?SidecarPasswords $passwords = null
    ): array {
        $passwords ??= SidecarPasswords::legacy();
        $engine = self::sidecarEngine($name, $service, $observedPorts);
        if ($engine === null || SidecarEngine::dialect($engine)['driver'] === null) {
            return $service;
        }

        // A database started with unresolved ${VAR} credentials comes up with
        // values the application cannot guess; settle them once so both sides
        // are configured from the same text. A password nobody set is the
        // account's own, not `app` (engine#189).
        $env = self::environmentMap($service['environment'] ?? null);
        $prefix = strtoupper($engine);
        foreach ($env as $key => $value) {
            if (str_starts_with(strtoupper((string) $key), $prefix . '_')) {
                $env[$key] = self::resolvedComposeValue((string) $value, self::fallbackFor((string) $key, $passwords));
            }
        }
        foreach (SidecarEngine::initVariablesFor($engine) as $variable) {
            $env[$variable] = self::resolvedComposeValue(
                (string) ($env[$variable] ?? ''),
                self::fallbackFor($variable, $passwords)
            );
        }
        $service['environment'] = $env;

        foreach (SidecarEngine::serviceOverridesFor($engine) as $key => $value) {
            $service[$key] = $key === 'environment' && is_array($value)
                ? array_merge($env, $value)
                : $value;
        }

        return $service;
    }

    private static function fallbackFor(string $variable, SidecarPasswords $passwords): string
    {
        return ComposePlaceholders::isSecretKey($variable) ? $passwords->for($variable) : 'app';
    }

    /**
     * Connection settings the application needs to reach one sidecar. An
     * engine with no dialect entry still gets its host, port and credentials
     * under its own prefix.
     *
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     * @return array<string, string>
     */
    public static function envForSidecar(string $name, array $service, array $observedPorts = []): array
    {
        $engine = self::sidecarEngine($name, $service, $observedPorts);
        $declared = self::environmentMap($service['environment'] ?? null);
        $ports = SidecarEngine::allPorts($service, $observedPorts);
        $dialect = SidecarEngine::dialect((string) $engine, $ports[0] ?? 0);

        $credentials = SidecarEngine::credentials($declared);
        $prefix = self::envPrefix(
            $credentials['prefix'] ?? self::dominantPrefix($declared),
            $engine,
            $name
        );
        if ($prefix === null) {
            return [];
        }

        $username = self::resolvedComposeValue((string) ($credentials['username'] ?? ''), '');
        $password = self::resolvedComposeValue((string) ($credentials['password'] ?? ''), '');
        $database = self::resolvedComposeValue((string) ($credentials['database'] ?? ''), '');

        $env = [$prefix . '_HOST' => $name];
        if ($dialect['port'] > 0) {
            $env[$prefix . '_PORT'] = (string) $dialect['port'];
        }

        // Whatever the service declares under its own prefix is handed back:
        // POSTGRES_PASSWORD, TYPESENSE_API_KEY and MEILI_MASTER_KEY arrive
        // without anyone enumerating them.
        $env += self::declaredVariablesUnderPrefix($declared, $prefix);

        $url = self::connectionUrl($dialect, $name, $username, $password, $database);
        if ($url !== null) {
            $env[$prefix . '_URL'] = $url;
        }

        return $env + self::frameworkEnvironment($dialect, $name, $username, $password, $database, $url);
    }

    /**
     * App-mode flags the dropped app service declared, which have no sidecar
     * equivalent (Sure's SELF_HOSTED).
     *
     * @param array<string, mixed> $service
     * @return array<string, string>
     */
    public static function envFromDroppedAppService(array $service): array
    {
        $map = self::environmentMap($service['environment'] ?? null);
        $out = [];
        foreach (['SELF_HOSTED', 'SELF_HOSTING_ENABLED'] as $key) {
            if (!isset($map[$key])) {
                continue;
            }
            $value = self::resolvedComposeValue((string) $map[$key], '');
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The workstation app service's whole `environment:`, for the app built in
     * its place. An unresolvable reference is dropped, never injected.
     *
     * @param array<string, mixed> $service
     * @param ?string $seed per-account secret for `${VAR:?}` credentials
     * @return array<string, string>
     */
    public static function envFromWorkstationAppService(array $service, ?string $seed = null): array
    {
        $out = [];
        foreach (self::environmentMap($service['environment'] ?? null) as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || ComposeEnvironment::isReserved($key)) {
                continue;
            }
            $resolved = self::harvestedValue($key, $value, $seed);
            if ($resolved !== null) {
                $out[$key] = $resolved;
            }
        }

        return $out;
    }

    /**
     * A kept sidecar's `environment:` with `${VAR:?}` credentials settled as
     * the harvested app env settles them, so both sides agree.
     */
    public static function withRequiredSecrets(mixed $environment, string $seed): mixed
    {
        $map = self::environmentMap($environment);
        $changed = false;
        foreach ($map as $key => $value) {
            $secret = ComposePlaceholders::requiredSecret((string) $key, $value, $seed);
            if ($secret !== null) {
                $map[$key] = $secret;
                $changed = true;
            }
        }

        return $changed ? $map : $environment;
    }

    private static function harvestedValue(string $key, string $value, ?string $seed): ?string
    {
        if ($seed !== null) {
            $secret = ComposePlaceholders::requiredSecret($key, $value, $seed);
            if ($secret !== null) {
                return $secret;
            }
        }
        if (!str_contains($value, '${')) {
            return $value;
        }
        // `${VAR:-default}` keeps its default; a bare `${VAR}`, `${VAR:?}` or
        // a reference inside a longer value has nothing to fill it.
        $resolved = self::resolvedComposeValue($value, '');

        return $resolved === '' || str_contains($resolved, '${') ? null : $resolved;
    }

    /**
     * @param mixed $environment
     * @return array<string, string>
     */
    public static function environmentMap($environment): array
    {
        if (!is_array($environment)) {
            return [];
        }
        $map = [];
        if (self::isEnvList($environment)) {
            foreach ($environment as $line) {
                if (!is_string($line) || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $map[$key] = $value;
            }

            return $map;
        }
        foreach ($environment as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts ports the image declares, when known
     */
    private static function sidecarEngine(string $name, array $service, array $observedPorts = []): ?string
    {
        return SidecarEngine::resolve($name, $service, $observedPorts);
    }

    /**
     * The prefix most of a service's variables share: meilisearch names its
     * settings `MEILI_*`. Two variables must agree, so a lone TZ or PUID
     * cannot claim the prefix.
     *
     * @param array<string, string> $declared
     */
    private static function dominantPrefix(array $declared): ?string
    {
        $counts = [];
        foreach (array_keys($declared) as $key) {
            $key = strtoupper(trim((string) $key));
            $head = explode('_', $key, 2);
            if (count($head) < 2 || $head[0] === '') {
                continue;
            }
            $counts[$head[0]] = ($counts[$head[0]] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $prefix = (string) array_key_first($counts);

        return $counts[$prefix] >= 2 ? $prefix : null;
    }

    /**
     * The service's own variables that belong to its prefix, resolved.
     *
     * @param array<string, string> $declared
     * @return array<string, string>
     */
    private static function declaredVariablesUnderPrefix(array $declared, string $prefix): array
    {
        $out = [];
        foreach ($declared as $key => $value) {
            $key = strtoupper(trim((string) $key));
            if (!str_starts_with($key, $prefix . '_')) {
                continue;
            }
            $resolved = self::resolvedComposeValue((string) $value, '');
            if ($resolved !== '') {
                $out[$key] = $resolved;
            }
        }

        return $out;
    }

    /**
     * The prefix the application will look under: the service's own variables
     * wherever they exist, then the engine's name, then the service's.
     */
    private static function envPrefix(?string $declaredPrefix, ?string $engine, string $serviceName): ?string
    {
        foreach ([$declaredPrefix, $engine, $serviceName] as $candidate) {
            $prefix = trim(strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $candidate)), '_');
            if ($prefix !== '' && preg_match('/^[A-Z]/', $prefix) === 1) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * @param array{engine: string, port: int, scheme: ?string, driver: ?string} $dialect
     */
    private static function connectionUrl(
        array $dialect,
        string $host,
        string $username,
        string $password,
        string $database
    ): ?string {
        if ($dialect['scheme'] === null || $dialect['port'] <= 0) {
            return null;
        }

        $credentials = '';
        if ($username !== '' || $password !== '') {
            $credentials = rawurlencode($username) . ':' . rawurlencode($password) . '@';
        }

        if ($database === '') {
            $database = (string) ($dialect['default_database'] ?? '');
        }

        return $dialect['scheme'] . '://' . $credentials . $host . ':' . $dialect['port']
            . ($database !== '' ? '/' . $database : '');
    }

    /**
     * The spellings frameworks expect, on top of the derived ones: Laravel
     * reads DB_CONNECTION and DB_HOST for any database, and CACHE_DRIVER for
     * its cache. They can never come out of the compose file, so they come
     * from the engine's entry in resources/sidecars/dialects.php.
     *
     * @param array{engine: string, port: int, scheme: ?string, driver: ?string, default_database: ?string, env: array<string, string>} $dialect
     * @return array<string, string>
     */
    private static function frameworkEnvironment(
        array $dialect,
        string $host,
        string $username,
        string $password,
        string $database,
        ?string $url
    ): array {
        $env = [];
        foreach ($dialect['env'] as $key => $value) {
            $env[(string) $key] = strtr((string) $value, [
                '{host}' => $host,
                '{port}' => (string) $dialect['port'],
            ]);
        }

        if ($dialect['driver'] === null) {
            return $env;
        }

        return $env + array_filter([
            'DB_CONNECTION' => $dialect['driver'],
            'DB_HOST' => $host,
            'DB_PORT' => (string) $dialect['port'],
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
            'DATABASE_URL' => (string) $url,
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * Compose `${VAR:-default}` keeps the default, so DATABASE_URL matches
     * what docker compose passes into the sidecar. Bare `${VAR}` falls back.
     */
    private static function resolvedComposeValue(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (preg_match('/^\$\{[A-Z0-9_]+(?::-([^}]*))?\}$/', $value, $m) !== 1) {
            return $value;
        }
        $default = $m[1] ?? '';

        return $default !== '' ? $default : $fallback;
    }

    /**
     * Compose's list form, decided by shape: a map whose one value contains
     * an `=` read as a list, and a list whose first entry has no `=` read as
     * a map. The wrong branch returns nothing for keys it should have found.
     *
     * @param array<mixed> $environment
     */
    private static function isEnvList(array $environment): bool
    {
        return $environment !== [] && array_is_list($environment);
    }
}
