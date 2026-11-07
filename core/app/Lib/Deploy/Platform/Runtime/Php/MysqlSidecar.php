<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Sidecar\SidecarPasswords;

/**
 * The database container a MySQL app gets when the account has no MySQL of its own, sharing the app's
 * network namespace, so the app reaches it on 127.0.0.1 with the credentials it was configured with.
 */
final class MysqlSidecar
{
    public const IMAGE = 'mariadb:11';

    /**
     * @param  array{connection?: string}  $db
     */
    public static function isNeeded(array $db): bool
    {
        return DatabaseSettings::fromArray($db)->isMysql();
    }

    /**
     * The settings with a blank password filled in, so the sidecar and DB_PASSWORD agree; a legacy account
     * keeps the blank its data directory was initialised with.
     *
     * @param  array<string, string>  $db
     * @return array<string, string>
     */
    public static function withPassword(array $db, SidecarPasswords $passwords): array
    {
        $settings = DatabaseSettings::fromArray($db);
        if ($passwords->isLegacy() || ! $settings->isMysql() || $settings->password() !== '') {
            return $db;
        }
        $db['password'] = $passwords->for(self::isRootAccount($settings) ? 'MYSQL_ROOT_PASSWORD' : 'MYSQL_PASSWORD');

        return $db;
    }

    /**
     * @param  array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string}  $db
     * @return array<string, mixed>
     */
    public static function service(array $db, ?SidecarPasswords $passwords = null): array
    {
        $settings = DatabaseSettings::fromArray($db);
        $passwords ??= SidecarPasswords::legacy();

        return [
            'image' => self::IMAGE,
            'network_mode' => 'service:app',
            'depends_on' => ['app'],
            'restart' => 'unless-stopped',
            'environment' => self::environment($settings, $passwords),
            'volumes' => ['dbdata:/var/lib/mysql'],
            'labels' => [GeneratedCompose::LABEL => 'framework-db'],
        ];
    }

    /**
     * A decision with the sidecar added and the app pointed at it, last in `env` to outrank `.env.example`.
     * `$db` is expected to have been through {@see withPassword()} already.
     *
     * @param  array<string, mixed>  $decision
     * @param  array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string}  $db
     * @return array<string, mixed>
     */
    public static function withSidecar(array $decision, array $db, ?SidecarPasswords $passwords = null): array
    {
        $env = is_array($decision['env'] ?? null) ? $decision['env'] : [];
        $decision['env'] = array_merge($env, self::connectionEnvironment(DatabaseSettings::fromArray($db)));

        return $decision + [
            'sidecars' => ['db' => self::service($db, $passwords)],
            'volumes' => ['dbdata' => null],
        ];
    }

    /**
     * The app's connection as compose `environment:`, which outranks `env_file:`: 127.0.0.1 (a `.env.example`'s
     * `db` resolves to nothing), the provisioned credentials, Laravel's `mysql` driver, and no unix socket.
     *
     * @return array<string, string>
     */
    public static function connectionEnvironment(DatabaseSettings $db): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => $db->port(),
            'DB_DATABASE' => $db->database(),
            'DB_USERNAME' => $db->username(),
            'DB_PASSWORD' => self::provisionedPassword($db),
            'DB_SOCKET' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function environment(DatabaseSettings $db, SidecarPasswords $passwords): array
    {
        return array_merge(
            ['MYSQL_DATABASE' => $db->database()],
            self::isRootAccount($db) ? self::rootCredentials($db) : self::userCredentials($db, $passwords)
        );
    }

    private static function isRootAccount(DatabaseSettings $db): bool
    {
        $user = trim((string) ($db->username()));

        return $user === '' || $user === 'root';
    }

    /**
     * @return array<string, string>
     */
    private static function rootCredentials(DatabaseSettings $db): array
    {
        return $db->password() === ''
            ? ['MYSQL_ALLOW_EMPTY_PASSWORD' => 'yes']
            : ['MYSQL_ROOT_PASSWORD' => $db->password()];
    }

    /**
     * The password the sidecar was started with. A blank one reaches here only
     * for a legacy account ({@see withPassword()}): root keeps it empty, a user gets `app`.
     */
    private static function provisionedPassword(DatabaseSettings $db): string
    {
        return self::isRootAccount($db) ? $db->password() : ($db->password() ?: SidecarPasswords::LEGACY);
    }

    /**
     * @return array<string, string>
     */
    private static function userCredentials(DatabaseSettings $db, SidecarPasswords $passwords): array
    {
        $password = self::provisionedPassword($db);

        return [
            'MYSQL_USER' => $db->username(),
            'MYSQL_PASSWORD' => $password,
            'MYSQL_ROOT_PASSWORD' => $passwords->mysqlRoot($password),
        ];
    }
}
