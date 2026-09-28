<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Sidecar\SidecarPasswords;

/**
 * The database container a MySQL app gets when the account has no MySQL of
 * its own.
 *
 * It shares the app's network namespace, so the app reaches it on 127.0.0.1
 * with the credentials it was already configured with.
 */
final class MysqlSidecar
{
    public const IMAGE = 'mariadb:11';

    /**
     * @param array{connection?: string} $db
     */
    public static function isNeeded(array $db): bool
    {
        return DatabaseSettings::fromArray($db)->isMysql();
    }

    /**
     * The settings with a password filled in where the project left it blank,
     * so the sidecar and the app's DB_PASSWORD agree on it (engine#189).
     *
     * A legacy account keeps the blank: its data directory was initialised
     * with `app` for a user, or an empty root password.
     *
     * @param array<string, string> $db
     * @return array<string, string>
     */
    public static function withPassword(array $db, SidecarPasswords $passwords): array
    {
        $settings = DatabaseSettings::fromArray($db);
        if ($passwords->isLegacy() || !$settings->isMysql() || $settings->password() !== '') {
            return $db;
        }
        $db['password'] = $passwords->for(self::isRootAccount($settings) ? 'MYSQL_ROOT_PASSWORD' : 'MYSQL_PASSWORD');

        return $db;
    }

    /**
     * @param array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string} $db
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
     * A decision with the sidecar added and the app pointed at it. The
     * connection goes last in `env`, so it outranks the `.env.example` the
     * decision was built from (engine#288).
     *
     * `$db` is expected to have been through {@see withPassword()} already.
     *
     * @param array<string, mixed> $decision
     * @param array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string} $db
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
     * The connection variables for the **application**, which shares this
     * container's network namespace.
     *
     * `network_mode: service:app` means the database is reached at
     * 127.0.0.1 and the service name `db` resolves to nothing. The repository
     * does not know that: Firefly III's `.env.example` sets `DB_HOST=db` for
     * its own workstation compose, and that file is written to `.env` and
     * carried into the container by `env_file:`, where the engine's generated
     * `environment:` still outranks it -- so without this the app dies on
     * `getaddrinfo for db failed` beside a database that is running and
     * healthy.
     *
     * Returned as compose `environment:` rather than written into `.env`,
     * because compose gives `environment:` precedence over `env_file:`: it
     * reaches the container whatever the file says, and the file the customer
     * opens still reads the way its author wrote it.
     *
     * The credentials are restated rather than assumed to survive: they are
     * what the container was provisioned with ({@see service()}), so they are
     * the ones that work, and a `.env.example` password is a placeholder like
     * any other.
     *
     * The connection is stated as `mysql` even when the example says
     * `mariadb`: this image is MariaDB, and Laravel's driver for both is
     * spelled `mysql`.
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
            // Empty, meaning "not a unix socket": a `.env.example` that
            // leaves it blank is fine, one that fills it in would otherwise
            // send the driver to a socket the sidecar does not create.
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
