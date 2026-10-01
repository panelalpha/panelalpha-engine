<?php

namespace App\Lib\Deploy\Sidecar;

use App\Lib\Deploy\Port\EnvVarDefault;

/**
 * Reading a datastore's variables by the shape of their names.
 *
 * `POSTGRES_USER`, `MYSQL_USER`, `INFLUXDB_USER` and `CLICKHOUSE_USER` are one
 * pattern — a prefix naming the engine, a suffix naming the role — which is
 * what lets an engine nobody catalogued still hand over its username,
 * password and database name.
 */
final class CredentialNames
{
    /**
     * Suffixes that name a credential's role, whatever engine it belongs to.
     *
     * @var array<string, list<string>>
     */
    private const ROLES = [
        'username' => ['_INITDB_ROOT_USERNAME', '_ROOT_USERNAME', '_DEFAULT_USER', '_USERNAME', '_USER'],
        'password' => ['_INITDB_ROOT_PASSWORD', '_ROOT_PASSWORD', '_DEFAULT_PASS', '_PASSWORD', '_PASS'],
        'database' => ['_INITDB_DATABASE', '_DEFAULT_VHOST', '_DATABASE', '_DB'],
    ];

    /**
     * Names that mark a service as a *client* of an engine rather than the
     * engine itself. A server has no need to be told its own address.
     *
     * @var list<string>
     */
    private const CLIENT_MARKERS = ['_HOST', '_HOSTNAME', '_URL', '_URI', '_DSN', '_ADDR', '_ENDPOINT'];

    /**
     * Client settings named outside the prefix: libpq's own PGHOST beside
     * POSTGRES_USER is an app configuring its client (AdventureLog).
     *
     * @var array<string, list<string>>
     */
    private const UNPREFIXED_CLIENT_MARKERS = [
        'POSTGRES' => ['PGHOST', 'PGHOSTADDR'],
        '' => ['DATABASE_URL'],
    ];

    /**
     * Suffixes naming the *administrative* account rather than the
     * application's. A service declaring both is describing two accounts, and
     * mixing them gives an app a username it has with a password it has not.
     *
     * @var list<string>
     */
    private const ROOT_SUFFIXES = [
        '_INITDB_ROOT_USERNAME',
        '_ROOT_USERNAME',
        '_INITDB_ROOT_PASSWORD',
        '_ROOT_PASSWORD',
    ];

    /**
     * @param array<string, string> $environment already-resolved env of the service
     * @return array{prefix: ?string, username: ?string, password: ?string, database: ?string}
     */
    public static function extract(array $environment): array
    {
        $found = ['prefix' => null, 'username' => null, 'password' => null, 'database' => null];
        $tiered = ['username' => [], 'password' => []];

        foreach ($environment as $key => $value) {
            $match = self::match(strtoupper(trim((string) $key)));
            if ($match === null) {
                continue;
            }
            $found['prefix'] ??= $match['prefix'];
            if ($found[$match['role']] === null) {
                $found[$match['role']] = (string) $value;
            }
            if ($match['role'] !== 'database') {
                $tier = in_array($match['suffix'], self::ROOT_SUFFIXES, true) ? 'root' : 'app';
                $tiered[$match['role']][$tier] ??= (string) $value;
            }
        }

        return self::pairedAccount($found, $tiered);
    }

    /**
     * Take the username and the password from the *same* account.
     *
     * Each role was resolved independently, first match in the environment's
     * own order, and MySQL is the case where that goes wrong: a service
     * declaring `MYSQL_ROOT_PASSWORD`, `MYSQL_USER` and `MYSQL_PASSWORD`
     * yields whichever password Docker happened to list first. Kanboard's
     * upstream compose lists the root one first, so the engine built
     * `mysql://kanboard:secret@db/kanboard` -- the application's username
     * with the administrator's password -- and the site served
     * `SQLSTATE[HY000] [1045] Access denied for user 'kanboard'`, on a deploy
     * reported as a success.
     *
     * The application's own account wins when the service declares one, which
     * is both what upstream intended and the lesser privilege. A service
     * declaring only root credentials -- Mongo's `_INITDB_ROOT_*` -- is
     * unaffected, and so is one declaring only an application account.
     *
     * @param array{prefix: ?string, username: ?string, password: ?string, database: ?string} $found
     * @param array{username: array<string, string>, password: array<string, string>} $tiered
     * @return array{prefix: ?string, username: ?string, password: ?string, database: ?string}
     */
    private static function pairedAccount(array $found, array $tiered): array
    {
        // The password decides, because it is the half that fails loudly.
        $tier = isset($tiered['password']['app']) ? 'app' : 'root';
        if (!isset($tiered['password'][$tier])) {
            return $found;
        }

        $found['password'] = $tiered['password'][$tier];
        // Its own tier's username, or the other one when the service names
        // only one -- a lone `MYSQL_USER` beside `MYSQL_ROOT_PASSWORD` is
        // still that user, and dropping it would be worse than pairing it.
        $found['username'] = $tiered['username'][$tier]
            ?? $tiered['username'][$tier === 'app' ? 'root' : 'app']
            ?? $found['username'];

        return $found;
    }

    /**
     * Prefixes whose variables name at least two roles: one shared name proves
     * nothing, two is a server declaring how to initialise itself.
     *
     * @param list<string> $keys uppercased environment keys
     * @return list<string>
     */
    public static function serverPrefixes(array $keys): array
    {
        $roles = [];
        foreach ($keys as $key) {
            $match = self::match($key);
            $prefix = $match === null ? '' : rtrim($match['prefix'], '_');
            if ($prefix !== '') {
                $roles[$prefix][$match['role']] = true;
            }
        }

        return array_keys(array_filter($roles, static fn (array $r): bool => count($r) >= 2));
    }

    /**
     * Whether the variables sharing a prefix describe a connection *to* an
     * engine rather than the engine itself.
     *
     * @param list<string> $keys
     */
    public static function isClientPrefix(string $prefix, array $keys): bool
    {
        foreach (self::UNPREFIXED_CLIENT_MARKERS as $family => $markers) {
            if (str_starts_with($prefix, $family) && array_intersect($markers, $keys) !== []) {
                return true;
            }
        }
        foreach ($keys as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            foreach (self::CLIENT_MARKERS as $marker) {
                if (str_ends_with($key, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether any `*_HOST` names another host by name: the service connects to
     * a server rather than being one. Zabbix's frontend carries `MYSQL_USER`
     * and `MYSQL_PASSWORD` beside `DB_SERVER_HOST: mysql`. A wildcard or a
     * local address (`MYSQL_ROOT_HOST: '%'`) is a server's own setting.
     *
     * @param array<string, string> $environment uppercased names
     */
    public static function namesARemoteHost(array $environment): bool
    {
        foreach ($environment as $key => $value) {
            if (!str_ends_with($key, '_HOST') && !str_ends_with($key, '_HOSTNAME')) {
                continue;
            }
            $host = strtolower(trim(EnvVarDefault::resolve($value), " \"'"));
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $host) === 1 && $host !== 'localhost') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{role: string, suffix: string, prefix: string}|null
     */
    private static function match(string $key): ?array
    {
        foreach (self::ROLES as $role => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (str_ends_with($key, $suffix) && strlen($key) > strlen($suffix)) {
                    return [
                        'role' => $role,
                        'suffix' => $suffix,
                        'prefix' => substr($key, 0, -strlen($suffix)),
                    ];
                }
            }
        }

        return null;
    }
}
