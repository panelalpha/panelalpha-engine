<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressInstaller;

/**
 * Substitute installer keeping the state a real one leaves on disk and in
 * MySQL, so a second run sees what the first made.
 */
final class RecordingInstaller implements WordpressInstaller
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public bool $core = false;

    public bool $installed = false;

    /** @var array<string, string> database => user */
    public array $databases = [];

    public function hasCore(User $user, string $path): bool
    {
        return $this->core;
    }

    public function downloadCore(User $user, string $path): void
    {
        $this->calls[] = ['downloadCore', $path];
        $this->core = true;
    }

    public function ensureDatabase(User $user, string $database, string $databaseUser, string $password): void
    {
        $this->calls[] = ['ensureDatabase', $database, $databaseUser];
        $this->databases[$database] = $databaseUser;
    }

    public function writeConfig(User $user, string $path, string $database, string $databaseUser, string $password): void
    {
        $this->calls[] = ['writeConfig', $database];
    }

    public function isInstalled(User $user, string $path): bool
    {
        return $this->installed;
    }

    public function install(User $user, string $path, string $siteUrl): void
    {
        $this->calls[] = ['install', $siteUrl];
        $this->installed = true;
    }
}
