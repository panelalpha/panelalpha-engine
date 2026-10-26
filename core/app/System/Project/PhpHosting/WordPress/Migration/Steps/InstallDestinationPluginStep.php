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
 * Step 3: install and activate the migrator plugin from a zip staged under the
 * account home (visible to the PHP container), then write config.php as the
 * account: destination mode and this migration's own token, which is stored.
 */
final class InstallDestinationPluginStep implements MigrationStep
{
    private const PLUGIN_SLUG = 'wordpress-migrator';

    public function __construct(
        private ?WordpressMigratorZip $zip = null,
        private ?AccountFileCopy $files = null,
    ) {
        $this->zip ??= new WordpressMigratorZip;
        $this->files ??= new AccountFileCopy;
    }

    /**
     * Copy the plugin zip where WP-CLI, running as the account, can read it.
     * In a subdirectory: place() opens its parent to 0755, and ~/.panelalpha
     * must stay 0700 and the account's.
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
        $label = $migration->target_label;
        if (! is_string($label) || $label === '') {
            throw new RuntimeException(
                'Cannot install destination plugin: target_label is missing.'
            );
        }

        $domain = Domain::findByName($label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$label}' not found for destination plugin install.");
        }

        $user = $domain->user;
        if (! $user instanceof User) {
            throw new RuntimeException("No user for domain '{$label}'.");
        }

        $chown = $user->getChownString();
        if ($chown === null || $chown === '') {
            throw new RuntimeException("No UID/GID for project '{$user->username}'.");
        }

        $home = rtrim($user->project()->homeDirPath(), '/');
        $docRoot = $home.$domain->getDocumentRoot();
        $subdir = SourceUrl::installSubdirectory($migration->source_url);
        $path = $subdir === '' ? $docRoot : $docRoot.'/'.$subdir;

        $zipPath = $this->zip->installZipFor($migration->id);
        if (! is_file($zipPath)) {
            throw new RuntimeException('Migrator install zip is missing after download/repack.');
        }

        $staged = $this->stageZip($zipPath, $home, $chown, (string) $migration->id);

        try {
            $wp = WordPress::for($user, $path);
            $wp->plugins()->install($staged, force: true);
            $wp->plugins()->activate(self::PLUGIN_SLUG);

            $token = MigratorDestinationConfig::tokenFor($migration->secretsForWorker()['migrator_token']);
            $configPath = $path.'/wp-content/plugins/'.self::PLUGIN_SLUG.'/config.php';
            $this->writeConfig(MigratorDestinationConfig::destinationBody($token), $configPath, $home, $chown);

            $migration->storeMigratorToken($token);
            $migration->markStepCompleted($this->name());
        } finally {
            $this->files->remove($staged, $home);
        }
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
