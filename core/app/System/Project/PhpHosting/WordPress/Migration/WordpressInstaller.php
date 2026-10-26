<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\User;

/**
 * The empty WordPress install_wordpress puts where the import lands. Every
 * operation may be repeated: a rerun finds what the first run left.
 */
interface WordpressInstaller
{
    public function hasCore(User $user, string $path): bool;

    public function downloadCore(User $user, string $path): void;

    /** Create the database and its user when missing, set the password, grant the user the database. */
    public function ensureDatabase(User $user, string $database, string $databaseUser, string $password): void;

    /** Write wp-config.php, over one a previous run left. */
    public function writeConfig(User $user, string $path, string $database, string $databaseUser, string $password): void;

    public function isInstalled(User $user, string $path): bool;

    public function install(User $user, string $path, string $siteUrl): void;
}
