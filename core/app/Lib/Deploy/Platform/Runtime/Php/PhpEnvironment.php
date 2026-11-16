<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\ComposeValues;

/**
 * The environment the generated PHP service runs with: production, logs to stderr, and pointed at
 * whichever database the account was given, or at none.
 */
final class PhpEnvironment
{
    private const SQLITE_PATH = '/app/database/database.sqlite';

    private const SQLITE_FILE = 'database.sqlite';

    /**
     * @param  array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string}  $db
     * @param  ?string  $databaseConfig  the project's config/database.php, read but never run
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
     * @param  array<string, string>  $engine
     * @return array<string, string>
     */
    public static function withManifest(array $engine, mixed $declared): array
    {
        return array_merge(ComposeValues::stringMap($declared), $engine);
    }

    /**
     * Symfony's production environment is spelled `prod`: given Laravel's `production`, symfony/runtime looks for
     * that config and wallabag died on `config_production.yml does not exist`.
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
        if (! is_string($appUrl) || $appUrl === '') {
            return [];
        }

        return ['APP_URL' => $appUrl, 'ASSET_URL' => $appUrl];
    }

    /**
     * The SQLite fallback is Laravel's alone: only its image creates the file and only it reads the variable.
     *
     * @return array<string, string>
     */
    private static function database(DatabaseSettings $db, bool $artisan, ?string $databaseConfig): array
    {
        if ($db->isMysql()) {
            return self::mysql($db);
        }

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
     * A bare filename where the config wraps the variable in database_path() anywhere in its `database` entry
     * (Heimdall), which would double an absolute path; otherwise the absolute path.
     */
    private static function sqlitePath(?string $databaseConfig): string
    {
        if ($databaseConfig === null
            || preg_match('/([\'"])sqlite\1\s*=>\s*(?:\[|array\s*\()([^\]]*)/', $databaseConfig, $block) !== 1
        ) {
            return self::SQLITE_PATH;
        }
        $wrapped = '/([\'"])database\1\s*=>(?:(?![\'"][\w.-]+[\'"]\s*=>).)*?'
            .'database_path\(\s*env\(\s*([\'"])DB_DATABASE\2\s*(?:,\s*([\'"])([^\'"]*)\3\s*)?\)/s';
        if (preg_match($wrapped, $block[2], $match) !== 1) {
            return self::SQLITE_PATH;
        }

        return ($match[4] ?? '') !== '' ? $match[4] : self::SQLITE_FILE;
    }
}
