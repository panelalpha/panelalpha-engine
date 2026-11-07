<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Lib\Deploy\Platform\Runtime\Php\DatabaseSettings;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use App\System\Services\Mysql;
use Illuminate\Support\Str;

/**
 * The database a platform manifest's `database: mysql` asks for, on the account's own MySQL server: a sidecar
 * would hold data the panel, phpMyAdmin and backups miss. Idempotent: a redeploy keeps the app's credentials.
 */
final class AppDatabase
{
    /**
     * Suffix for both the database and its user, after the account's own prefix (`alice_app`). Short, because
     * MySQL stops at 64 characters and 32 of those can already be gone on the prefix.
     */
    public const SUFFIX = 'app';

    public const PASSWORD_DETAIL = 'app_db_password';

    private const PASSWORD_BYTES = 24;

    private const PORT = '3306';

    private const FALLBACK_HOST = 'database-users.shared-hosting.palocal';

    /**
     * Credentials in the shape {@see DatabaseSettings} reads, so the generated compose and the app's environment
     * are written by the code that already does that for Laravel.
     *
     * @return array{connection: string, host: string, port: string, database: string, username: string, password: string}
     */
    public static function provision(User $user, ?Mysql $mysql = null): array
    {
        $mysql ??= new Mysql;
        $name = self::nameFor($user);
        $password = self::password($user);

        self::ensureDatabase($mysql, $user, $name);
        self::ensureUser($mysql, $user, $name, $password);
        $mysql->privileges()->updatePrivileges($name, $name, 'ALL PRIVILEGES');

        return self::credentials($name, $password);
    }

    /**
     * Whether the deploy gave $user its application database: the row {@see ensureDatabase()} writes is what
     * the panel lists and the teardown walks, so it is the record of one.
     */
    public static function isOwnedBy(User $user): bool
    {
        return $user->mysqlDatabases()->getQuery()->where('database', self::nameFor($user))->exists();
    }

    /**
     * The credentials {@see provision()} handed $user's application, read without touching the server: null
     * when no password is stored or it cannot be decoded.
     *
     * @return array{connection: string, host: string, port: string, database: string, username: string, password: string}|null
     */
    public static function credentialsOf(User $user): ?array
    {
        $stored = $user->getDetails()[self::PASSWORD_DETAIL] ?? null;

        return is_string($stored) && $stored !== '' ? self::credentials(self::nameFor($user), $stored) : null;
    }

    /**
     * @return array{connection: string, host: string, port: string, database: string, username: string, password: string}
     */
    private static function credentials(string $name, string $password): array
    {
        return [
            'connection' => 'mysql',
            'host' => self::hostname(),
            'port' => self::PORT,
            'database' => $name,
            'username' => $name,
            'password' => $password,
        ];
    }

    /**
     * The app, several NATs away in nested Docker, cannot resolve the database's name but can route to its address:
     * the name is pinned, so its config records a hostname, not an address a recreated database container changes.
     *
     * @return list<string> a compose `extra_hosts` list, empty when the name
     *                      resolves to nothing worth pinning
     */
    public static function extraHosts(?DindProject $account = null): array
    {
        $host = self::hostname();
        $address = ($account !== null && $host === self::FALLBACK_HOST ? self::tenantNetworkAddress($account) : null)
            ?? gethostbyname($host);

        return $address === $host || filter_var($address, FILTER_VALIDATE_IP) === false
            ? []
            : ["{$host}:{$address}"];
    }

    /**
     * $yaml with every `extra_hosts` pin of the database name moved to $address: after a network change the old
     * one is unreachable. An operator's own database is not sites-db and did not move.
     */
    public static function repinned(string $yaml, string $address): string
    {
        if (self::hostname() !== self::FALLBACK_HOST) {
            return $yaml;
        }
        $name = preg_quote(self::hostname(), '/');

        return (string) preg_replace('/(?<='.$name.':)\d{1,3}(?:\.\d{1,3}){3}\b/', $address, $yaml);
    }

    /**
     * Core resolves sites-db on its own network, which an account on
     * pash-tenants cannot reach; there it has a pinned address.
     */
    private static function tenantNetworkAddress(DindProject $account): ?string
    {
        try {
            return TenantNetwork::sitesDbAddressFor(
                $account->system()->exec(TenantNetwork::accountNetworksArgv($account->username()), [], 30)
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public static function nameFor(User $user): string
    {
        return $user->getMysqlPrefix().self::SUFFIX;
    }

    public static function hostname(): string
    {
        /** @var mixed $host */
        $host = config('env.USERS_DB_HOST');

        return is_string($host) && trim($host) !== '' ? trim($host) : self::FALLBACK_HOST;
    }

    /**
     * Generated once: a new one would lock the app out, its own config file still holding the old one. For the same
     * reason a stored password that cannot be decoded is an error, never a reason to generate a new one.
     */
    private static function password(User $user): string
    {
        $details = $user->getDetails();
        $stored = $details[self::PASSWORD_DETAIL] ?? null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        if ($user->hasUnreadableSecret(self::PASSWORD_DETAIL)) {
            throw new \RuntimeException(
                "The application database password stored for '{$user->username}' cannot be decoded. "
                    .'It is kept, and deploys stop until it can be decoded again: a new password would lock the '
                    .'application out of its own database.'
            );
        }

        $password = Str::random(self::PASSWORD_BYTES);
        $user->setDetails([self::PASSWORD_DETAIL => $password]);
        $user->save();

        return $password;
    }

    /**
     * The row is what the panel lists and what the account's teardown walks, so a database on the server without
     * one is invisible and outlives the account that owns it.
     */
    private static function ensureDatabase(Mysql $mysql, User $user, string $name): void
    {
        if (! $mysql->databases()->databaseExists($name)) {
            $mysql->databases()->createDatabase($name);
        }
        if (! $user->mysqlDatabases()->getQuery()->where('database', $name)->exists()) {
            MysqlDatabase::create(['user_id' => $user->id, 'database' => $name]);
        }
    }

    /**
     * An existing user gets the stored password set on it rather than being trusted to already have it: the row
     * and the server can disagree after a restore, and the app has no way to ask which is right.
     */
    private static function ensureUser(Mysql $mysql, User $user, string $name, string $password): void
    {
        if ($mysql->users()->userExists($name)) {
            $mysql->users()->changeUserPassword($name, $password);
        } else {
            $mysql->users()->createUser($name, $password);
        }
        if (! $user->mysqlUsers()->getQuery()->where('user', $name)->exists()) {
            MysqlUser::create(['user_id' => $user->id, 'user' => $name]);
        }
    }
}
