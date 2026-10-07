<?php

namespace App\Lib\Deploy\Sidecar;

use App\Lib\Deploy\Platform\Runtime\Php\MysqlSidecar;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\EnvFile;
use Symfony\Component\Yaml\Yaml;

/**
 * Backing services implied by a project's .env when the repo ships no compose
 * of its own.
 *
 * Framework recipes (TanStack, Nuxt, …) often only declare DATABASE_URL /
 * REDIS_URL pointing at localhost. Without a sidecar the generated app
 * container starts, then every request 500s. This class turns those URLs into
 * the same {services, volumes, env} shape {@see ComposeHarden::extractRuntimeSidecarsFromYaml()}
 * returns, so {@see DeployStrategy} can merge them unchanged.
 *
 * External hosts are left alone — the operator already runs the database.
 *
 * No Laravel dependencies — unit-testable with a temp directory.
 */
class EnvSidecars
{
    /**
     * URL keys that name a datastore. First match per engine wins.
     *
     * @var list<string>
     */
    private const URL_KEYS = [
        'DATABASE_URL',
        'POSTGRES_URL',
        'MYSQL_URL',
        'REDIS_URL',
        'MONGODB_URL',
        'MONGO_URL',
    ];

    /**
     * Hostnames that mean "I expected a local companion process", not a
     * managed database somewhere else.
     *
     * @var list<string>
     */
    private const LOCAL_HOSTS = [
        '',
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '::1',
        'host.docker.internal',
    ];

    /**
     * Stock images when the env file names an engine but no compose does.
     *
     * @var array<string, string>
     */
    private const IMAGES = [
        'postgres' => 'postgres:16',
        'mysql' => MysqlSidecar::IMAGE,
        'redis' => 'redis:7',
        'mongo' => 'mongo:7',
    ];

    /**
     * Compose service name per engine — also the hostname written into
     * DATABASE_URL / REDIS_URL.
     *
     * @var array<string, string>
     */
    private const SERVICE_NAMES = [
        'postgres' => 'db',
        'mysql' => 'db',
        'redis' => 'redis',
        'mongo' => 'mongo',
    ];

    /**
     * @param (callable(string): ?string)|null $read
     * @return array{
     *   services: array<string, array<string, mixed>>,
     *   volumes: array<string, mixed>,
     *   env: array<string, string>,
     * }
     */
    public static function fromProjectDir(string $projectDir, ?callable $read = null, ?SidecarPasswords $passwords = null): array
    {
        return self::fromVariables(self::variableMap($projectDir, $read), $passwords);
    }

    /**
     * @param array<string, string> $variables
     * @param ?SidecarPasswords $passwords fills a password the URL left out;
     *        null keeps the legacy `app`
     * @return array{
     *   services: array<string, array<string, mixed>>,
     *   volumes: array<string, mixed>,
     *   env: array<string, string>,
     * }
     */
    public static function fromVariables(array $variables, ?SidecarPasswords $passwords = null): array
    {
        $passwords ??= SidecarPasswords::legacy();
        $empty = ['services' => [], 'volumes' => [], 'env' => []];
        $draft = [];
        foreach (self::URL_KEYS as $key) {
            $raw = trim((string) ($variables[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $spec = self::specFromUrl($raw);
            if ($spec === null) {
                continue;
            }
            $engine = $spec['engine'];
            if (isset($draft[$engine])) {
                continue;
            }
            $draft[$engine] = $spec;
        }

        if ($draft === []) {
            $fromDb = self::specFromDbConnection($variables);
            if ($fromDb !== null) {
                $draft[$fromDb['engine']] = $fromDb;
            }
        }

        if ($draft === []) {
            return $empty;
        }

        $services = [];
        $volumes = [];
        foreach ($draft as $engine => $spec) {
            $name = self::SERVICE_NAMES[$engine] ?? $engine;
            // Two database engines both want "db" — keep the first.
            if (isset($services[$name])) {
                continue;
            }
            $built = self::serviceDefinition($engine, $spec, $passwords);
            if ($built['service'] === []) {
                continue;
            }
            $services[$name] = $built['service'];
            foreach ($built['volumes'] as $volume => $def) {
                $volumes[$volume] = $def;
            }
        }

        if ($services === []) {
            return $empty;
        }

        $compose = ['services' => $services];
        if ($volumes !== []) {
            $compose['volumes'] = $volumes;
        }

        return ComposeHarden::extractRuntimeSidecarsFromYaml(
            Yaml::dump($compose, 4, 2),
            true
        );
    }

    /**
     * `.env.example` first, then `.env` so live values win. `$read` as in
     * {@see EnvFile::databaseSettings()}.
     *
     * @param (callable(string): ?string)|null $read
     * @return array<string, string>
     */
    public static function variableMap(string $projectDir, ?callable $read = null): array
    {
        $map = [];
        $projectDir = rtrim($projectDir, '/');
        $read ??= EnvFile::readHostFile(...);
        foreach (['.env.example', '.env'] as $name) {
            $contents = $read($projectDir . '/' . $name);
            if (!is_string($contents) || $contents === '') {
                continue;
            }
            foreach (EnvFile::parse($contents) as $row) {
                if (($row['type'] ?? '') !== 'variable') {
                    continue;
                }
                $key = (string) ($row['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $map[$key] = (string) ($row['value'] ?? '');
            }
        }

        return $map;
    }

    /**
     * @return array{engine: string, username: string, password: string, database: string}|null
     */
    private static function specFromUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $engine = self::engineFromScheme($scheme);
        if ($engine === null) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!self::isLocalHost($host)) {
            return null;
        }

        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        if (str_contains($database, '?')) {
            $database = strstr($database, '?', true) ?: $database;
        }
        if ($database === '' && $engine === 'redis') {
            $database = '0';
        }

        return [
            'engine' => $engine,
            'username' => (string) ($parts['user'] ?? ''),
            'password' => (string) ($parts['pass'] ?? ''),
            'database' => $database,
        ];
    }

    /**
     * Laravel-style DB_* without a URL (same signal PhpStrategy uses).
     *
     * @param array<string, string> $variables
     * @return array{engine: string, username: string, password: string, database: string}|null
     */
    private static function specFromDbConnection(array $variables): ?array
    {
        $connection = strtolower(trim((string) ($variables['DB_CONNECTION'] ?? '')));
        $engine = match ($connection) {
            'pgsql', 'postgres', 'postgresql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            default => null,
        };
        if ($engine === null) {
            return null;
        }
        $host = strtolower(trim((string) ($variables['DB_HOST'] ?? '127.0.0.1')));
        if ($host !== '' && !self::isLocalHost($host)) {
            return null;
        }

        return [
            'engine' => $engine,
            'username' => (string) ($variables['DB_USERNAME'] ?? ''),
            'password' => (string) ($variables['DB_PASSWORD'] ?? ''),
            'database' => (string) ($variables['DB_DATABASE'] ?? ''),
        ];
    }

    private static function engineFromScheme(string $scheme): ?string
    {
        return match ($scheme) {
            'postgres', 'postgresql', 'pgsql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            'redis', 'rediss' => 'redis',
            'mongodb', 'mongo' => 'mongo',
            default => null,
        };
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array($host, self::LOCAL_HOSTS, true);
    }

    /**
     * @param array{engine: string, username: string, password: string, database: string} $spec
     * @return array{service: array<string, mixed>, volumes: array<string, mixed>}
     */
    private static function serviceDefinition(string $engine, array $spec, SidecarPasswords $passwords): array
    {
        $image = self::IMAGES[$engine] ?? null;
        if ($image === null) {
            return ['service' => [], 'volumes' => []];
        }

        [$user, $pass, $database] = self::credentials($engine, $spec, $passwords);

        $service = [
            'image' => $image,
            'restart' => 'unless-stopped',
            'labels' => [
                GeneratedCompose::LABEL => 'env-sidecar',
            ],
        ];

        $environment = self::environmentFor($engine, $user, $pass, $database, $passwords);
        if ($environment !== []) {
            $service['environment'] = $environment;
        }

        $volumes = [];
        $dataPath = self::DATA_PATHS[$engine] ?? null;
        if ($dataPath !== null) {
            $service['volumes'] = ['dbdata:' . $dataPath];
            $volumes['dbdata'] = null;
        }

        $limit = SidecarEngine::memoryLimitFor($engine);
        if ($limit !== null) {
            $service['mem_limit'] = $limit;
        }

        return ['service' => $service, 'volumes' => $volumes];
    }

    /**
     * Where each engine keeps its data, and therefore which ones need a volume.
     *
     * Redis is absent on purpose: a cache the engine invented on the strength
     * of a REDIS_URL is not somewhere to persist anything.
     *
     * @var array<string, string>
     */
    private const DATA_PATHS = [
        'postgres' => '/var/lib/postgresql/data',
        'mysql' => '/var/lib/mysql',
        'mongo' => '/data/db',
    ];

    /**
     * The init variable that holds each engine's password; the engines that
     * refuse to start without one.
     *
     * @var array<string, string>
     */
    private const PASSWORD_VARIABLES = [
        'postgres' => 'POSTGRES_PASSWORD',
        'mysql' => 'MYSQL_PASSWORD',
        'mongo' => 'MONGO_INITDB_ROOT_PASSWORD',
    ];

    /**
     * The credentials this sidecar will accept, filled in where the URL did
     * not say.
     *
     * A datastore with no password is unreachable rather than open — these
     * images refuse to start without one — so the blank is filled for the
     * engines that demand it, with a per-account password rather than the
     * `app` everyone could guess. Redis numbers its databases
     * rather than naming them, which is why its default is `0` and not `app`.
     *
     * @param array{username: string, password: string, database: string} $spec
     * @return array{0: string, 1: string, 2: string} user, password, database
     */
    private static function credentials(string $engine, array $spec, SidecarPasswords $passwords): array
    {
        $user = trim($spec['username']) !== '' ? trim($spec['username']) : 'app';

        $pass = (string) $spec['password'];
        $variable = self::PASSWORD_VARIABLES[$engine] ?? null;
        if ($pass === '' && $variable !== null) {
            $pass = $passwords->for($engine === 'mysql' && $user === 'root' ? 'MYSQL_ROOT_PASSWORD' : $variable);
        }

        $database = trim($spec['database']);
        if ($database === '') {
            $database = $engine === 'redis' ? '0' : 'app';
        }

        return [$user, $pass, $database];
    }

    /**
     * The variables each official image reads to seed itself on first boot.
     *
     * @return array<string, string>
     */
    private static function environmentFor(
        string $engine,
        string $user,
        string $pass,
        string $database,
        SidecarPasswords $passwords
    ): array {
        return match ($engine) {
            'postgres' => [
                'POSTGRES_DB' => $database,
                'POSTGRES_USER' => $user,
                'POSTGRES_PASSWORD' => $pass,
            ],
            'mysql' => self::mysqlEnvironment($user, $pass, $database, $passwords),
            'mongo' => [
                'MONGO_INITDB_DATABASE' => $database,
                'MONGO_INITDB_ROOT_USERNAME' => $user,
                'MONGO_INITDB_ROOT_PASSWORD' => $pass,
            ],
            default => [],
        };
    }

    /**
     * MySQL is the awkward one: it will not create `root` as a normal user, so
     * a URL naming root sets the root password instead of a user pair.
     *
     * There is no MYSQL_ALLOW_EMPTY_PASSWORD branch, because there cannot be
     * one: {@see credentials()} fills an absent password for every engine
     * that refuses to start without one, so `$pass` is never empty by the
     * time it arrives here.
     *
     * Root no longer shares the user's password unless the account is legacy.
     *
     * @return array<string, string>
     */
    private static function mysqlEnvironment(string $user, string $pass, string $database, SidecarPasswords $passwords): array
    {
        $root = $user === 'root' ? $pass : $passwords->mysqlRoot($pass);
        $env = ['MYSQL_DATABASE' => $database, 'MYSQL_ROOT_PASSWORD' => $root];

        return $user === 'root' ? $env : $env + [
            'MYSQL_USER' => $user,
            'MYSQL_PASSWORD' => $pass,
        ];
    }
}
