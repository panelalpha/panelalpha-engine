<?php

namespace App\Lib\Deploy\Sidecar;

use RuntimeException;

/**
 * The catalogue of backing services the engine can speak to: ports, schemes,
 * drivers, the variables each image reads at first start.
 *
 * A plain data file rather than Laravel config, so everything that consults
 * it stays loadable and testable without a container. Operators add their own
 * entries in a JSON file merged over the shipped one — an in-house datastore
 * is a config change, not a release.
 */
final class SidecarDialects
{
    private const SHIPPED_FILE = __DIR__ . '/../../../../resources/sidecars/dialects.php';

    private const OVERRIDE_PATH = '/etc/panelalpha/sidecar-dialects.json';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $entries = null;

    /** @var array<string, string>|null alias => canonical engine */
    private static ?array $aliases = null;

    /**
     * A missing file used to fall back to an empty set, on the reasoning that
     * degrading beats breaking. It does not: with no dialects nothing is
     * recognised as a datastore, a sidecars-only compose file reads as an
     * application, and the deploy goes green having quietly dropped the
     * database. Moving this class one directory deeper was enough to trigger
     * it, so the path is checked loudly instead.
     *
     * @return array<string, array<string, mixed>>
     * @throws RuntimeException when the shipped catalogue cannot be read
     */
    public static function all(): array
    {
        return self::$entries ??= self::load();
    }

    public static function has(string $engine): bool
    {
        return isset(self::all()[$engine]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(string $engine): array
    {
        return self::all()[(string) self::canonical($engine)] ?? [];
    }

    /** One name per engine, so `mariadb` and `mysql` are configured alike. */
    public static function canonical(string $engine): ?string
    {
        $engine = strtolower(trim($engine));
        if ($engine === '') {
            return null;
        }

        return self::aliases()[$engine] ?? $engine;
    }

    /**
     * Everything known about how to address an engine.
     *
     * @return array{engine: string, port: int, scheme: ?string, driver: ?string, default_database: ?string, env: array<string, string>}
     */
    public static function dialect(string $engine, int $observedPort = 0): array
    {
        $known = self::entry($engine);

        return [
            'engine' => (string) self::canonical($engine),
            'port' => $observedPort > 0 ? $observedPort : (int) ($known['port'] ?? 0),
            'scheme' => $known['scheme'] ?? null,
            'driver' => $known['driver'] ?? null,
            'default_database' => $known['default_database'] ?? null,
            'env' => is_array($known['env'] ?? null) ? $known['env'] : [],
        ];
    }

    public static function portFor(string $engine): int
    {
        return self::dialect($engine)['port'];
    }

    /**
     * Variables a server image reads at first start.
     *
     * @return list<string>
     */
    public static function initVariablesFor(string $engine): array
    {
        $vars = self::entry($engine)['init_vars'] ?? [];

        return is_array($vars) ? array_values(array_map('strval', $vars)) : [];
    }

    /**
     * The superuser password a server image refuses to start without, and the
     * variables that stand in for it (a `_FILE`, trust auth, a random one).
     *
     * @return array{passwords: list<string>, waivers: list<string>}|null
     */
    public static function passwordVariablesFor(string $engine): ?array
    {
        $entry = self::entry($engine)['init_password'] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $list = static fn ($v): array => is_array($v) ? array_values(array_map('strval', $v)) : [];

        return ['passwords' => $list($entry['passwords'] ?? null), 'waivers' => $list($entry['waivers'] ?? null)];
    }

    /**
     * Variables the server image reads that are named like a client's
     * connection settings (`MYSQL_ROOT_HOST`).
     *
     * @return list<string>
     */
    public static function serverSettingsFor(string $engine): array
    {
        $vars = self::entry($engine)['server_settings'] ?? [];

        return is_array($vars) ? array_values(array_map('strval', $vars)) : [];
    }

    /** How much memory an engine needs, or null when the catalogue is silent. */
    public static function memoryLimitFor(string $engine): ?string
    {
        $limit = self::entry($engine)['mem_limit'] ?? null;

        return is_string($limit) && $limit !== '' ? $limit : null;
    }

    /**
     * Compose keys an engine's image needs in order to be usable — a
     * healthcheck, a setting the image will not start without.
     *
     * @return array<string, mixed>
     */
    public static function serviceOverridesFor(string $engine): array
    {
        $overrides = self::entry($engine)['service'] ?? [];

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Ports no web application serves on, taken from the entries that say so.
     *
     * @return list<int>
     */
    public static function unambiguousPorts(): array
    {
        $ports = [];
        foreach (self::all() as $entry) {
            if (($entry['unambiguous_port'] ?? false) === true && (int) ($entry['port'] ?? 0) > 0) {
                $ports[] = (int) $entry['port'];
            }
        }

        return array_values(array_unique($ports));
    }

    /** Test seam: forget the loaded data so an override can be exercised. */
    public static function forget(): void
    {
        self::$entries = null;
        self::$aliases = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function load(): array
    {
        if (!is_readable(self::SHIPPED_FILE)) {
            throw new RuntimeException(
                'Sidecar dialect catalogue is missing or unreadable: ' . self::SHIPPED_FILE
            );
        }

        $shipped = require self::SHIPPED_FILE;
        $entries = is_array($shipped) ? $shipped : [];
        foreach (self::operatorAdditions() as $engine => $entry) {
            if (is_string($engine) && is_array($entry)) {
                $key = strtolower($engine);
                $entries[$key] = array_merge($entries[$key] ?? [], $entry);
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operatorAdditions(): array
    {
        if (!is_readable(self::OVERRIDE_PATH)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents(self::OVERRIDE_PATH), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Flattened from the entries' own alias lists, so an engine is described
     * in exactly one place.
     *
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        self::$aliases = [];
        foreach (self::all() as $engine => $entry) {
            foreach ((array) ($entry['aliases'] ?? []) as $alias) {
                self::$aliases[strtolower((string) $alias)] = (string) $engine;
            }
        }

        return self::$aliases;
    }
}
