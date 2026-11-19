<?php

namespace App\Lib\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposeInterpolation;
use App\Lib\Deploy\Compose\ComposePlaceholders;

/**
 * Connection settings for a compose sidecar (database, cache, search engine), derived from its own service
 * definition and generated upper-cased under its prefix: `<PREFIX>_HOST`, `_PORT`, `_URL`, `<PREFIX>_*`, `DB_*`.
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
     * with a password nobody set taken from the account, not `app`. One the
     * service reads from a file (`POSTGRES_PASSWORD_FILE`) is left unset: the
     * official images refuse a variable set both ways.
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
            if (self::readFromFile($env, $variable)) {
                continue;
            }
            $env[$variable] = self::resolvedComposeValue(
                (string) ($env[$variable] ?? ''),
                self::fallbackFor($variable, $passwords)
            );
        }

        return $env;
    }

    /** @param  array<string, string>  $env */
    private static function readFromFile(array $env, string $variable): bool
    {
        return trim((string) ($env[$variable.'_FILE'] ?? '')) !== '' && ! array_key_exists($variable, $env);
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
     * Connection settings the application needs to reach one sidecar. An engine with no dialect entry still
     * gets its host, port and credentials, plus every variable it declares, under its own prefix.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     * @return array<string, string>
     */
    public static function envForSidecar(string $name, array $service, array $observedPorts = []): array
    {
        return (new SidecarConnectionEnv(self::resolvedComposeValue(...)))->envFor($name, $service, $observedPorts);
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
     * Secret-looking keys {@see envFromWorkstationAppService()} would copy verbatim: a literal in the repository's
     * compose file, the same for every deploy of it, not a per-account `${VAR:?}` reference.
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

    /**
     * A kept sidecar's bare `${SECRET}` in `environment:`, `command:` or `healthcheck.test` that nothing sets, given
     * one generated value: Compose would pass '', redis refuses an empty `--requirepass`, meilisearch an empty
     * production master key, and a `redis-cli -a ${REDIS_PASSWORD}` check left empty is refused by the server.
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
        $service = self::withHealthcheckFilled($service, $fill);

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
        if (array_key_exists('command', $service)) {
            $service['command'] = self::filledArgv($service['command'], $fill);
        }

        return $service;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  \Closure(string, ?string): string  $fill
     * @return array<string, mixed>
     */
    private static function withHealthcheckFilled(array $service, \Closure $fill): array
    {
        if (! is_array($service['healthcheck'] ?? null) || ! array_key_exists('test', $service['healthcheck'])) {
            return $service;
        }
        $service['healthcheck']['test'] = self::filledArgv($service['healthcheck']['test'], $fill);

        return $service;
    }

    /**
     * @param  \Closure(string, ?string): string  $fill
     */
    private static function filledArgv(mixed $argv, \Closure $fill): mixed
    {
        if (is_string($argv)) {
            return $fill($argv, null);
        }
        if (! is_array($argv)) {
            return $argv;
        }

        return array_map(static fn (mixed $part): mixed => is_string($part) ? $fill($part, null) : $part, $argv);
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
     * A service's own variable as Compose settles it with the variable unset, so DATABASE_URL matches the sidecar.
     * Only a value that is one whole reference is resolved; one that settles to nothing falls back.
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
     * Compose's list form, decided by shape and not by `=`: guessing from a value or a first entry
     * reads a map as a list or a list as a map, and the wrong branch finds none of its keys.
     *
     * @param  array<mixed>  $environment
     */
    private static function isEnvList(array $environment): bool
    {
        return $environment !== [] && array_is_list($environment);
    }
}
