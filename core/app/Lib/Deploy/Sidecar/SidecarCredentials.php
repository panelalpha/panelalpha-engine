<?php

namespace App\Lib\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposeInterpolation;
use App\Lib\Deploy\Compose\ComposePlaceholders;

/**
 * Connection settings for a compose sidecar (database, cache, search engine),
 * derived from the sidecar's own service definition and generated upper-cased
 * under its prefix: `<PREFIX>_HOST`, `_PORT`, `_URL`, `<PREFIX>_*`, `DB_*`.
 */
class SidecarCredentials
{
    /** `${NAME}` with no default or `:?`; `$${...}` is compose's escape. */
    private const BARE_REFERENCE = '/(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /**
     * A database's unresolved `${VAR}` credentials settled once, so the
     * sidecar and the application are configured from the same text.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts  ports the image declares, when known
     * @param  ?SidecarPasswords  $passwords  fills a password nobody set; null
     *                                        keeps the legacy `app`
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

        $env = self::settledEngineEnvironment(self::environmentMap($service['environment'] ?? null), $engine, $passwords);
        $service['environment'] = $env;

        return self::withServiceOverrides($service, $engine, $env);
    }

    /**
     * The engine's prefixed and init variables resolved as Compose would,
     * with a password nobody set taken from the account, not `app`.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private static function settledEngineEnvironment(array $env, string $engine, SidecarPasswords $passwords): array
    {
        $prefix = strtoupper($engine);
        foreach ($env as $key => $value) {
            if (str_starts_with(strtoupper((string) $key), $prefix.'_')) {
                $env[$key] = self::resolvedComposeValue((string) $value, self::fallbackFor((string) $key, $passwords));
            }
        }
        foreach (SidecarEngine::initVariablesFor($engine) as $variable) {
            $env[$variable] = self::resolvedComposeValue(
                (string) ($env[$variable] ?? ''),
                self::fallbackFor($variable, $passwords)
            );
        }

        return $env;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private static function withServiceOverrides(array $service, string $engine, array $env): array
    {
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
     * under its own prefix, plus every variable it declares under that prefix.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
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

        [$username, $password, $database] = self::resolvedCredentials($credentials, $service['command'] ?? null);

        $env = self::hostAndPort($prefix, $name, $dialect) + self::declaredVariablesUnderPrefix($declared, $prefix);
        $url = self::uninterpolatedUrl($dialect, $name, $username, $password, $database);
        if ($url !== null) {
            $env[$prefix.'_URL'] = $url;
        }

        return $env + self::frameworkEnvironment($dialect, $name, $username, $password, $database, $url);
    }

    /**
     * Username, password and database as Compose settles them; a blank
     * password falls back to redis's `--requirepass`.
     *
     * @param  array{prefix: ?string, username: ?string, password: ?string, database: ?string}  $credentials
     * @return array{0: string, 1: string, 2: string}
     */
    private static function resolvedCredentials(array $credentials, mixed $command): array
    {
        $username = self::resolvedComposeValue((string) ($credentials['username'] ?? ''), '');
        $password = self::resolvedComposeValue((string) ($credentials['password'] ?? ''), '');
        if ($password === '') {
            $password = self::requirePassOf($command);
        }
        $database = self::resolvedComposeValue((string) ($credentials['database'] ?? ''), '');

        return [$username, $password, $database];
    }

    /**
     * @param  array{engine: string, port: int, scheme: ?string, driver: ?string}  $dialect
     * @return array<string, string>
     */
    private static function hostAndPort(string $prefix, string $host, array $dialect): array
    {
        $env = [$prefix.'_HOST' => $host];
        if ($dialect['port'] > 0) {
            $env[$prefix.'_PORT'] = (string) $dialect['port'];
        }

        return $env;
    }

    /**
     * The connection URL, or null while the password still carries a
     * reference: nothing interpolates a *_URL, so the app would get it literally.
     *
     * @param  array{engine: string, port: int, scheme: ?string, driver: ?string}  $dialect
     */
    private static function uninterpolatedUrl(
        array $dialect,
        string $host,
        string $username,
        string $password,
        string $database
    ): ?string {
        return self::unresolved($password) ? null : self::connectionUrl($dialect, $host, $username, $password, $database);
    }

    /** Whether a value still carries a Compose reference the sidecar would leave literal. */
    private static function unresolved(string $value): bool
    {
        return str_contains($value, '$');
    }

    /**
     * App-mode flags the dropped app service declared, which have no sidecar
     * equivalent (Sure's SELF_HOSTED).
     *
     * @param  array<string, mixed>  $service
     * @return array<string, string>
     */
    public static function envFromDroppedAppService(array $service): array
    {
        $map = self::environmentMap($service['environment'] ?? null);
        $out = [];
        foreach (['SELF_HOSTED', 'SELF_HOSTING_ENABLED'] as $key) {
            if (! isset($map[$key])) {
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
     * @param  array<string, mixed>  $service
     * @param  ?string  $seed  per-account secret for `${VAR:?}` credentials
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
     * Secret-looking keys that {@see envFromWorkstationAppService()} would copy
     * verbatim: a literal already sitting in the repository's compose file, the
     * same for every deploy of it, not a per-account `${VAR:?}` reference.
     *
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    public static function publishedSecretsIn(array $service): array
    {
        $out = [];
        foreach (self::environmentMap($service['environment'] ?? null) as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || ComposeEnvironment::isReserved($key) || str_contains($value, '${')) {
                continue;
            }
            if (trim($value) !== '' && ComposePlaceholders::isSecretKey($key)) {
                $out[] = $key;
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

    /** Redis's `--requirepass <literal>`, so its URL carries the password; '' otherwise. */
    private static function requirePassOf(mixed $command): string
    {
        if (preg_match('/--requirepass[ =]+("?)([^\s"]+)\1/', self::commandText($command), $m) !== 1) {
            return '';
        }
        $password = self::resolvedComposeValue($m[2], '');

        return self::unresolved($password) ? '' : $password;
    }

    /** A `command:` in either form as one line; '' for anything else. */
    private static function commandText(mixed $command): string
    {
        if (is_array($command)) {
            return implode(' ', array_filter($command, 'is_string'));
        }

        return is_string($command) ? $command : '';
    }

    /**
     * A kept sidecar's bare `${SECRET}` in `environment:` or `command:` that
     * nothing will set, replaced with a generated value. Compose would pass an
     * empty string: redis then refuses `--requirepass` with no argument and
     * meilisearch refuses an empty master key in production.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the file with
     * @return array{0: array<string, mixed>, 1: array<string, string>} the service, NAME => value generated
     */
    public static function withBareSecretsFilled(array $service, string $seed, array $env): array
    {
        $generated = [];
        $fill = static function (string $text, ?string $key) use ($seed, $env, &$generated): string {
            return self::bareSecretsFilledIn($text, $key, $seed, $env, $generated);
        };

        $service = self::withEnvironmentFilled($service, $fill);
        $service = self::withCommandFilled($service, $fill);

        return [$service, $generated];
    }

    /**
     * @param  array<string, list<?string>|string>  $env
     * @param  array<string, string>  $generated  NAME => value, extended in place
     */
    private static function bareSecretsFilledIn(string $text, ?string $key, string $seed, array $env, array &$generated): string
    {
        return (string) preg_replace_callback(
            self::BARE_REFERENCE,
            static function (array $m) use ($key, $seed, $env, &$generated): string {
                $name = $m[1];
                if (! self::isUnsetSecret($name, $key, $env)) {
                    return $m[0];
                }

                return $generated[$name] ??= ComposePlaceholders::requiredSecretValue($name, $seed);
            },
            $text
        );
    }

    /**
     * Whether `${NAME}` is a secret, by its own name or the key it sits under,
     * that compose has no non-blank value for.
     *
     * @param  array<string, list<?string>|string>  $env
     */
    private static function isUnsetSecret(string $name, ?string $key, array $env): bool
    {
        if (! ComposePlaceholders::isSecretKey($name) && ($key === null || ! ComposePlaceholders::isSecretKey($key))) {
            return false;
        }
        foreach ((array) ($env[$name] ?? []) as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  \Closure(string, ?string): string  $fill
     * @return array<string, mixed>
     */
    private static function withEnvironmentFilled(array $service, \Closure $fill): array
    {
        if (! isset($service['environment']) || ! is_array($service['environment'])) {
            return $service;
        }
        $map = self::environmentMap($service['environment']);
        $filled = [];
        foreach ($map as $key => $value) {
            $filled[$key] = $fill((string) $value, (string) $key);
        }
        if ($filled !== $map) {
            $service['environment'] = $filled;
        }

        return $service;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  \Closure(string, ?string): string  $fill
     * @return array<string, mixed>
     */
    private static function withCommandFilled(array $service, \Closure $fill): array
    {
        $command = $service['command'] ?? null;
        if (is_string($command)) {
            $service['command'] = $fill($command, null);
        } elseif (is_array($command)) {
            $service['command'] = array_map(
                static fn (mixed $part): mixed => is_string($part) ? $fill($part, null) : $part,
                $command
            );
        }

        return $service;
    }

    /**
     * `${VAR:-default}` keeps its default; a bare `${VAR}`, `${VAR:?}` without
     * a seed, or a reference inside a longer value has nothing to fill it: null.
     */
    private static function harvestedValue(string $key, string $value, ?string $seed): ?string
    {
        if ($seed !== null) {
            $secret = ComposePlaceholders::requiredSecret($key, $value, $seed);
            if ($secret !== null) {
                return $secret;
            }
        }
        if (! str_contains($value, '${')) {
            return $value;
        }
        $resolved = self::resolvedComposeValue($value, '');

        return $resolved === '' || str_contains($resolved, '${') ? null : $resolved;
    }

    /**
     * @param  mixed  $environment
     * @return array<string, string>
     */
    public static function environmentMap($environment): array
    {
        if (! is_array($environment)) {
            return [];
        }

        return self::isEnvList($environment) ? self::mapFromEnvList($environment) : self::mapFromEnvMap($environment);
    }

    /**
     * @param  list<mixed>  $environment  `KEY=value` lines
     * @return array<string, string>
     */
    private static function mapFromEnvList(array $environment): array
    {
        $map = [];
        foreach ($environment as $line) {
            if (! is_string($line) || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @param  array<mixed>  $environment
     * @return array<string, string> only string keys with scalar, non-bool values
     */
    private static function mapFromEnvMap(array $environment): array
    {
        $map = [];
        foreach ($environment as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts  ports the image declares, when known
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
     * @param  array<string, string>  $declared
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
     * @param  array<string, string>  $declared
     * @return array<string, string>
     */
    private static function declaredVariablesUnderPrefix(array $declared, string $prefix): array
    {
        $out = [];
        foreach ($declared as $key => $value) {
            $key = strtoupper(trim((string) $key));
            if (! str_starts_with($key, $prefix.'_')) {
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
     * @param  array{engine: string, port: int, scheme: ?string, driver: ?string}  $dialect
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
            $credentials = rawurlencode($username).':'.rawurlencode($password).'@';
        }

        if ($database === '') {
            $database = (string) ($dialect['default_database'] ?? '');
        }

        return $dialect['scheme'].'://'.$credentials.$host.':'.$dialect['port']
            .($database !== '' ? '/'.$database : '');
    }

    /**
     * The spellings frameworks expect, on top of the derived ones: Laravel
     * reads DB_CONNECTION and DB_HOST for any database, and CACHE_DRIVER for
     * its cache. They can never come out of the compose file, so they come
     * from the engine's entry in resources/sidecars/dialects.php.
     *
     * @param  array{engine: string, port: int, scheme: ?string, driver: ?string, default_database: ?string, env: array<string, string>}  $dialect
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
        $env = self::dialectEnvironment($dialect, $host);
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
     * The dialect's own `env` entries with `{host}` and `{port}` filled in.
     *
     * @param  array{port: int, env: array<string, string>}  $dialect
     * @return array<string, string>
     */
    private static function dialectEnvironment(array $dialect, string $host): array
    {
        $env = [];
        foreach ($dialect['env'] as $key => $value) {
            $env[(string) $key] = strtr((string) $value, [
                '{host}' => $host,
                '{port}' => (string) $dialect['port'],
            ]);
        }

        return $env;
    }

    /**
     * A service's own variable as Compose settles it with the variable unset,
     * so DATABASE_URL matches what the sidecar gets. Only a value that is one
     * whole reference is resolved; one that settles to nothing falls back.
     */
    private static function resolvedComposeValue(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (preg_match('/^\$(?:\{[^{}]*\}|[A-Za-z_][A-Za-z0-9_]*)$/', $value) !== 1) {
            return $value;
        }
        $resolved = self::interpolateUnset($value);
        if ($resolved === null) {
            return $value;
        }

        return $resolved !== '' ? $resolved : $fallback;
    }

    /**
     * What the reference is worth with every variable it names unset. Null when
     * the interpreter cannot tell (bad syntax, a process variable).
     */
    private static function interpolateUnset(string $value): ?string
    {
        $env = [];
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $value, $m) !== false) {
            foreach ($m[0] as $name) {
                $env[$name] = [null];
            }
        }
        $candidates = ComposeInterpolation::candidates($value, $env);

        return $candidates === null || $candidates === [] ? null : $candidates[0];
    }

    /**
     * Compose's list form, decided by shape: a map whose one value contains
     * an `=` read as a list, and a list whose first entry has no `=` read as
     * a map. The wrong branch returns nothing for keys it should have found.
     *
     * @param  array<mixed>  $environment
     */
    private static function isEnvList(array $environment): bool
    {
        return $environment !== [] && array_is_list($environment);
    }
}
