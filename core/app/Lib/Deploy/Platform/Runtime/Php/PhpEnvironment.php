<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\ComposeValues;

/**
 * The environment the generated PHP service runs with: production, logs to
 * stderr where Docker can read them, and pointed at whichever database the
 * account was given -- or at none, which is a thing an app is allowed to be.
 */
final class PhpEnvironment
{
    private const SQLITE_PATH = '/app/database/database.sqlite';

    private const SQLITE_FILE = 'database.sqlite';

    /**
     * @param array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string} $db
     * @param ?string $databaseConfig the project's config/database.php, read but never run
     * @return array<string, string>
     */
    public static function for(
        array $db,
        ?string $appUrl,
        bool $artisan = true,
        bool $symfony = false,
        ?string $databaseConfig = null
    ): array {
        return array_merge(
            ['APP_ENV' => self::appEnv($symfony), 'LOG_CHANNEL' => 'stderr'],
            self::url($appUrl),
            self::database(DatabaseSettings::fromArray($db), $artisan, $databaseConfig)
        );
    }

    /**
     * The engine's environment over the matched manifest's `env:`. The manifest
     * fills keys the engine does not set; the engine's own values still win.
     *
     * @param array<string, string> $engine
     * @return array<string, string>
     */
    public static function withManifest(array $engine, mixed $declared): array
    {
        return array_merge(ComposeValues::stringMap($declared), $engine);
    }

    /**
     * Symfony's production environment is spelled `prod`, and only `prod`.
     *
     * `production` is Laravel's word, and shipping it to a Symfony app is
     * fatal rather than untidy: symfony/runtime reads APP_ENV and then looks
     * for the config of that name, so wallabag died on
     *
     *     The file "/app/app/config/config_production.yml" does not exist.
     *
     * before it rendered a single byte. The deploy skill already warns an
     * operator to correct this by hand with env_vars; the engine knows which
     * framework it detected, so it should not need telling.
     */
    private static function appEnv(bool $symfony): string
    {
        return $symfony ? 'prod' : 'production';
    }

    /**
     * @return array<string, string>
     */
    private static function url(?string $appUrl): array
    {
        if (!is_string($appUrl) || $appUrl === '') {
            return [];
        }

        return ['APP_URL' => $appUrl, 'ASSET_URL' => $appUrl];
    }

    /**
     * @return array<string, string>
     */
    private static function database(DatabaseSettings $db, bool $artisan, ?string $databaseConfig): array
    {
        if ($db->isMysql()) {
            return self::mysql($db);
        }

        // Only Laravel gets the SQLite fallback, because only Laravel's image
        // creates the file it points at and only Laravel reads the variable.
        // A plain PHP app used to be told its database was a path that did
        // not exist, which is worse than being told nothing.
        return $artisan ? self::sqlite($databaseConfig) : [];
    }

    /**
     * @return array<string, string>
     */
    private static function mysql(DatabaseSettings $db): array
    {
        return [
            'DB_CONNECTION' => $db->driver(),
            'DB_HOST' => $db->host(),
            'DB_PORT' => $db->port(),
            'DB_DATABASE' => $db->database(),
            'DB_USERNAME' => $db->username(),
            'DB_PASSWORD' => $db->password(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function sqlite(?string $databaseConfig): array
    {
        return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => self::sqlitePath($databaseConfig)];
    }

    /**
     * Heimdall wraps the variable in database_path(), so an absolute path doubles.
     * Such a config gets a bare filename; anything else keeps the absolute path.
     * The wrap may sit anywhere in the `database` entry: Heimdall now puts a
     * `:memory:` ternary in front of it.
     */
    private static function sqlitePath(?string $databaseConfig): string
    {
        if ($databaseConfig === null
            || preg_match('/([\'"])sqlite\1\s*=>\s*(?:\[|array\s*\()([^\]]*)/', $databaseConfig, $block) !== 1
        ) {
            return self::SQLITE_PATH;
        }
        $wrapped = '/([\'"])database\1\s*=>(?:(?![\'"][\w.-]+[\'"]\s*=>).)*?'
            . 'database_path\(\s*env\(\s*([\'"])DB_DATABASE\2\s*(?:,\s*([\'"])([^\'"]*)\3\s*)?\)/s';
        if (preg_match($wrapped, $block[2], $match) !== 1) {
            return self::SQLITE_PATH;
        }

        return ($match[4] ?? '') !== '' ? $match[4] : self::SQLITE_FILE;
    }
}
