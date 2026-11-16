<?php

namespace App\Lib\Deploy\Sidecar;

/**
 * The connection settings the application gets for one sidecar: host, port, URL and declared variables under
 * the sidecar's prefix, plus the framework spellings ({@see SidecarCredentials::envForSidecar()}).
 */
final class SidecarConnectionEnv
{
    /**
     * @param  \Closure(string, string): string  $settle  a value as Compose settles it, else the fallback
     */
    public function __construct(private readonly \Closure $settle) {}

    /**
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     * @return array<string, string>
     */
    public function envFor(string $name, array $service, array $observedPorts): array
    {
        $engine = SidecarEngine::resolve($name, $service, $observedPorts);
        $declared = SidecarCredentials::environmentMap($service['environment'] ?? null);
        $ports = SidecarEngine::allPorts($service, $observedPorts);
        $dialect = SidecarEngine::dialect((string) $engine, $ports[0] ?? 0);

        $credentials = SidecarEngine::credentials($declared);
        $prefix = self::envPrefix($credentials['prefix'] ?? self::dominantPrefix($declared), $engine, $name);
        if ($prefix === null) {
            return [];
        }

        [$username, $password, $database] = $this->resolvedCredentials($credentials, $service['command'] ?? null);

        $env = self::hostAndPort($prefix, $name, $dialect) + $this->declaredVariablesUnderPrefix($declared, $prefix);
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
    private function resolvedCredentials(array $credentials, mixed $command): array
    {
        $username = ($this->settle)((string) ($credentials['username'] ?? ''), '');
        $password = ($this->settle)((string) ($credentials['password'] ?? ''), '');
        if ($password === '') {
            $password = $this->requirePassOf($command);
        }
        $database = ($this->settle)((string) ($credentials['database'] ?? ''), '');

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

    /** Redis's `--requirepass <literal>`, so its URL carries the password; '' otherwise. */
    private function requirePassOf(mixed $command): string
    {
        if (preg_match('/--requirepass[ =]+("?)([^\s"]+)\1/', self::commandText($command), $m) !== 1) {
            return '';
        }
        $password = ($this->settle)($m[2], '');

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
     * The prefix most of a service's variables share (meilisearch's `MEILI_*`). Two variables
     * must agree, so a lone TZ or PUID cannot claim it.
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
    private function declaredVariablesUnderPrefix(array $declared, string $prefix): array
    {
        $out = [];
        foreach ($declared as $key => $value) {
            $key = strtoupper(trim((string) $key));
            if (! str_starts_with($key, $prefix.'_')) {
                continue;
            }
            $resolved = ($this->settle)((string) $value, '');
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
     * The spellings frameworks expect on top of the derived ones, such as Laravel's DB_CONNECTION and CACHE_DRIVER;
     * the compose file never has them, so they come from the engine's entry in resources/sidecars/dialects.php.
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
}
