<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use App\System\Project\PhpHosting\WordPress\Migration\ImportedProject;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressInstaller;
use App\System\Project\PhpHosting\WordPress\Migration\WpCliWordpressInstaller;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Step 2: install empty WordPress (core download, database, config, core
 * install) into the path derived from the source URL. A rerun keeps the core
 * already downloaded, reuses the database the record names and skips an
 * install that is done, so a resumed import leaves one of each.
 */
final class InstallWordpressStep implements MigrationStep
{
    public function __construct(
        private ?WordpressInstaller $installer = null,
        private ?AccountFileCopy $files = null,
    ) {
        $this->installer ??= new WpCliWordpressInstaller;
        $this->files ??= new AccountFileCopy;
    }

    public function name(): string
    {
        return WordpressMigration::STEP_INSTALL_WORDPRESS;
    }

    public function run(WordpressMigration $migration): void
    {
        [$user, $domain] = $this->target($migration);
        $path = ImportedProject::installPath($user, $domain, $migration->source_url);
        $this->ensureSubdirectory($user, $path, $migration->source_url);

        if (! $this->installer->hasCore($user, $path)) {
            $this->installer->downloadCore($user, $path);
        }

        [$database, $databaseUser] = $this->databaseNames($migration, $user);
        $password = Str::random(16);
        $this->installer->ensureDatabase($user, $database, $databaseUser, $password);
        $this->installer->writeConfig($user, $path, $database, $databaseUser, $password);

        if (! $this->installer->isInstalled($user, $path)) {
            $this->installer->install($user, $path, SourceUrl::destinationSiteUrl((string) $migration->target_label, $migration->source_url));
        }

        $migration->markStepCompleted($this->name());
    }

    /**
     * @return array{0: User, 1: Domain}
     */
    private function target(WordpressMigration $migration): array
    {
        $label = $migration->target_label;
        if (! is_string($label) || $label === '') {
            throw new RuntimeException(
                'Cannot install WordPress: target_label is missing (create_project must complete first).'
            );
        }

        $domain = Domain::findByName($label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$label}' not found for WordPress install.");
        }

        $user = $domain->user;
        if (! $user instanceof User) {
            throw new RuntimeException("No user for domain '{$label}'.");
        }

        return [$user, $domain];
    }

    private function ensureSubdirectory(User $user, string $path, string $sourceUrl): void
    {
        if (SourceUrl::installSubdirectory($sourceUrl) === '') {
            return;
        }

        $chown = $user->getChownString();
        if (! is_string($chown) || $chown === '') {
            throw new RuntimeException('Cannot install WordPress: account uid:gid is missing.');
        }
        $this->files->ensureDirectory($path, $user->project()->homeDirPath(), $chown);
    }

    /**
     * The names a previous run recorded, or new ones recorded before anything
     * is created.
     *
     * @return array{0: string, 1: string}
     */
    private function databaseNames(WordpressMigration $migration, User $user): array
    {
        if (is_string($migration->created_database) && $migration->created_database !== ''
            && is_string($migration->created_database_user) && $migration->created_database_user !== '') {
            return [$migration->created_database, $migration->created_database_user];
        }

        $name = $user->getMysqlPrefix().'wp'.substr(bin2hex(random_bytes(2)), 0, 4);
        $migration->storeCreatedDatabase($name, $name);

        return [$name, $name];
    }
}
