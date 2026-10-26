<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting\WordPress\Core;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Support\Str;

/**
 * WP-CLI as the account for core and config, the users' MySQL server for the
 * database. The destination admin password is throwaway: the migrator
 * overwrites the site's content and users.
 */
final class WpCliWordpressInstaller implements WordpressInstaller
{
    private const DEFAULT_DB_HOST = 'database-users.shared-hosting.palocal';

    public function hasCore(User $user, string $path): bool
    {
        return WordPress::for($user, $path)->cli()->installHasFile(Core::VERSION_FILE);
    }

    public function downloadCore(User $user, string $path): void
    {
        WordPress::for($user, $path)->core()->download();
    }

    public function ensureDatabase(User $user, string $database, string $databaseUser, string $password): void
    {
        $mysql = (new System)->mysql();
        if (! $mysql->databases()->databaseExists($database)) {
            $mysql->databases()->createDatabase($database);
        }
        MysqlDatabase::query()->firstOrCreate(['user_id' => $user->id, 'database' => $database]);

        if ($mysql->users()->userExists($databaseUser)) {
            $mysql->users()->changeUserPassword($databaseUser, $password);
        } else {
            $mysql->users()->createUser($databaseUser, $password);
        }
        MysqlUser::query()->firstOrCreate(['user_id' => $user->id, 'user' => $databaseUser]);
        $mysql->privileges()->updatePrivileges($databaseUser, $database, 'ALL PRIVILEGES');
    }

    public function writeConfig(User $user, string $path, string $database, string $databaseUser, string $password): void
    {
        WordPress::for($user, $path)->cli()->runOrFail([
            'config',
            'create',
            '--dbname='.$database,
            '--dbuser='.$databaseUser,
            '--dbpass='.$password,
            '--dbhost='.self::databaseHost(),
            '--force',
        ]);
    }

    public function isInstalled(User $user, string $path): bool
    {
        return WordPress::for($user, $path)->core()->status()['installed'];
    }

    public function install(User $user, string $path, string $siteUrl): void
    {
        WordPress::for($user, $path)->core()->install([
            'url' => $siteUrl,
            'title' => 'Migrated site',
            'admin_user' => 'admin',
            'admin_email' => self::adminEmail(),
            'admin_password' => Str::random(20),
        ]);
    }

    private static function databaseHost(): string
    {
        $host = config('env.USERS_DB_HOST');

        return is_string($host) && $host !== '' ? $host : self::DEFAULT_DB_HOST;
    }

    private static function adminEmail(): string
    {
        $email = (string) Setting::get('email');

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'admin@localhost.localdomain';
    }
}
