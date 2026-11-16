<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigratorDestinationConfig;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use App\System\Project\PhpHosting\WordPress\WordPress;
use RuntimeException;

/**
 * Step 3: install and activate the migrator plugin from a zip staged under the account home, then write its
 * config.php as the account, in destination mode with this migration's own token, which is stored.
 */
final class InstallDestinationPluginStep implements MigrationStep
{
    private const PLUGIN_SLUG = 'wordpress-migrator';

    public function __construct(
        private ?WordpressMigratorZip $zip = null,
        private ?AccountFileCopy $files = null,
    ) {
        $this->zip ??= new WordpressMigratorZip(WordpressMigratorZip::JOB_DOWNLOAD_SECONDS);
        $this->files ??= new AccountFileCopy;
    }

    /**
     * Copy the plugin zip where WP-CLI, running as the account, can read it: in a subdirectory, since place()
     * opens its parent to 0755 and ~/.panelalpha must stay 0700 and the account's.
     */
    public function stageZip(string $zipPath, string $home, string $chown, string $migrationId): string
    {
        $this->files->ensureDirectory($home.'/.panelalpha', $home, $chown, '0700');
        $staged = $home.'/.panelalpha/'.self::PLUGIN_SLUG.'/'.$migrationId.'.zip';
        $this->files->place($zipPath, $staged, $home, $chown);

        return $staged;
    }

    public function name(): string
    {
        return WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN;
    }

    public function run(WordpressMigration $migration): void
    {
        $label = $this->targetLabel($migration);
        $domain = $this->targetDomain($label);
        $user = $this->domainUser($domain, $label);
        $chown = $this->accountChown($user);

        $home = rtrim($user->project()->homeDirPath(), '/');
        $path = $this->installPath($migration, $home.$domain->getDocumentRoot());

        $zipPath = $this->installZip($migration);
        $staged = $this->stageZip($zipPath, $home, $chown, (string) $migration->id);

        try {
            $this->installAndConfigure($migration, $user, $path, $staged, $home, $chown);
        } finally {
            $this->files->remove($staged, $home, $chown);
        }
    }

    private function targetLabel(WordpressMigration $migration): string
    {
        $label = $migration->target_label;
        if (! is_string($label) || $label === '') {
            throw new RuntimeException(
                'Cannot install destination plugin: target_label is missing.'
            );
        }

        return $label;
    }

    private function targetDomain(string $label): Domain
    {
        $domain = Domain::findByName($label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$label}' not found for destination plugin install.");
        }

        return $domain;
    }

    private function domainUser(Domain $domain, string $label): User
    {
        $user = $domain->user;
        if (! $user instanceof User) {
            throw new RuntimeException("No user for domain '{$label}'.");
        }

        return $user;
    }

    private function accountChown(User $user): string
    {
        $chown = $user->getChownString();
        if ($chown === null || $chown === '') {
            throw new RuntimeException("No UID/GID for project '{$user->username}'.");
        }

        return $chown;
    }

    /** The document root, or the subdirectory of it the source site was installed in. */
    private function installPath(WordpressMigration $migration, string $docRoot): string
    {
        $subdir = SourceUrl::installSubdirectory($migration->source_url);

        return $subdir === '' ? $docRoot : $docRoot.'/'.$subdir;
    }

    private function installZip(WordpressMigration $migration): string
    {
        $zipPath = $this->zip->installZipFor($migration->id);
        if (! is_file($zipPath)) {
            throw new RuntimeException('Migrator install zip is missing after download/repack.');
        }

        return $zipPath;
    }

    private function installAndConfigure(WordpressMigration $migration, User $user, string $path, string $staged, string $home, string $chown): void
    {
        $wp = WordPress::for($user, $path);
        $wp->plugins()->install($staged, force: true);
        $wp->plugins()->activate(self::PLUGIN_SLUG);

        $token = MigratorDestinationConfig::tokenFor($migration->secretsForWorker()['migrator_token']);
        $configPath = $path.'/wp-content/plugins/'.self::PLUGIN_SLUG.'/config.php';
        $this->writeConfig(MigratorDestinationConfig::destinationBody($token), $configPath, $home, $chown);

        $migration->storeMigratorToken($token);
        $migration->markStepCompleted($this->name());
    }

    private function writeConfig(string $body, string $configPath, string $home, string $chown): void
    {
        $tmp = sys_get_temp_dir().'/wp-migrator-config-'.bin2hex(random_bytes(8)).'.php';
        if (file_put_contents($tmp, $body) === false) {
            throw new RuntimeException('Could not write temporary destination config.');
        }
        try {
            $this->files->place($tmp, $configPath, $home, $chown);
        } finally {
            @unlink($tmp);
        }
    }
}
