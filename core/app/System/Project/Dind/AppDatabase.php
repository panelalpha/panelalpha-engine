<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use App\System\Services\Mysql;
use Illuminate\Support\Str;

/**
 * The database an application gets when its platform says it needs one.
 *
 * Laravel points itself at a database through its own .env, and a project
 * shipping a compose file brings its own. Everything else -- Matomo,
 * and every PHP CMS shaped like it -- has no way to say so, and used to get
 * `DB_CONNECTION=sqlite` pointing at a file the image never created. The
 * platform manifest says `database: mysql` instead, and this answers it.
 *
 * On the account's own MySQL server, not a sidecar: the account already has
 * one, so a second costs a container and a volume to hold data the panel
 * cannot see, phpMyAdmin cannot open and the account's backup does not
 * include.
 *
 * Idempotent, because a redeploy must not hand the app different credentials
 * than the ones it wrote into its own config file on the first one. The
 * password is generated once and kept in the account's encrypted details;
 * everything else is derived.
 */
final class AppDatabase
{
    /**
     * Suffix for both the database and its user, after the account's own
     * prefix -- `alice_app`. Short, because MySQL stops at 64 characters and
     * 32 of those can already be gone on the prefix.
     */
    public const SUFFIX = 'app';

    public const PASSWORD_DETAIL = 'app_db_password';

    private const PASSWORD_BYTES = 24;

    private const PORT = '3306';

    private const FALLBACK_HOST = 'database-users.shared-hosting.palocal';

    /**
     * Credentials in the shape {@see \App\Lib\Deploy\Platform\Runtime\Php\DatabaseSettings}
     * reads, so the generated compose and the app's environment are written
     * by the code that already does that for Laravel.
     *
     * @return array{connection: string, host: string, port: string, database: string, username: string, password: string}
     */
    public static function provision(User $user, ?Mysql $mysql = null): array
    {
        $mysql ??= new Mysql();
        $name = self::nameFor($user);
        $password = self::password($user);

        self::ensureDatabase($mysql, $user, $name);
        self::ensureUser($mysql, $user, $name, $password);
        $mysql->privileges()->updatePrivileges($name, $name, 'ALL PRIVILEGES');

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
     * The application container resolves nothing the engine's own network
     * resolves: it lives in the account's nested Docker, several NATs away
     * from the compose network the database sits on. It can still route to
     * the address, so the deploy pins the name to it and the app records a
     * hostname in its config file rather than an address that changes the
     * next time the database container is recreated.
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
     * $yaml with every `extra_hosts` pin of the database name moved to
     * $address. An account changing network (engine#519) leaves its app
     * pinned to an address it can no longer reach.
     */
    public static function repinned(string $yaml, string $address): string
    {
        // A database of the operator's own is not sites-db and did not move.
        if (self::hostname() !== self::FALLBACK_HOST) {
            return $yaml;
        }
        $name = preg_quote(self::hostname(), '/');

        return (string) preg_replace('/(?<=' . $name . ':)\d{1,3}(?:\.\d{1,3}){3}\b/', $address, $yaml);
    }

    /**
     * Core resolves sites-db on its own network, which an account on
     * pash-tenants cannot reach (engine#519); there it has a pinned address.
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
        return $user->getMysqlPrefix() . self::SUFFIX;
    }

    public static function hostname(): string
    {
        /** @var mixed $host */
        $host = config('env.USERS_DB_HOST');

        return is_string($host) && trim($host) !== '' ? trim($host) : self::FALLBACK_HOST;
    }

    /**
     * Generated once. A redeploy that rotated it would leave the app holding
     * the old one in its own config file -- Matomo's config.ini.php,
     * WordPress's wp-config.php -- and the site would come back up unable to
     * reach a database that was working a minute earlier. For the same reason
     * a stored password that cannot be decrypted is an error, never a reason
     * to generate a new one.
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
                "The application database password stored for '{$user->username}' cannot be decrypted "
                    . '(APP_KEY changed?). Restore the previous APP_KEY; a new password would lock the '
                    . 'application out of its own database.'
            );
        }

        $password = Str::random(self::PASSWORD_BYTES);
        $user->setDetails([self::PASSWORD_DETAIL => $password]);
        $user->save();

        return $password;
    }

    private static function ensureDatabase(Mysql $mysql, User $user, string $name): void
    {
        if (!$mysql->databases()->databaseExists($name)) {
            $mysql->databases()->createDatabase($name);
        }
        // The row is what the panel lists and what the account's teardown
        // walks, so a database on the server without one is invisible and
        // outlives the account that owns it.
        if (!$user->mysqlDatabases()->getQuery()->where('database', $name)->exists()) {
            MysqlDatabase::create(['user_id' => $user->id, 'database' => $name]);
        }
    }

    /**
     * An existing user gets the stored password set on it rather than being
     * trusted to already have it: the row and the server can disagree after a
     * restore, and the app has no way to ask which is right.
     */
    private static function ensureUser(Mysql $mysql, User $user, string $name, string $password): void
    {
        if ($mysql->users()->userExists($name)) {
            $mysql->users()->changeUserPassword($name, $password);
        } else {
            $mysql->users()->createUser($name, $password);
        }
        if (!$user->mysqlUsers()->getQuery()->where('user', $name)->exists()) {
            MysqlUser::create(['user_id' => $user->id, 'user' => $name]);
        }
    }
}
