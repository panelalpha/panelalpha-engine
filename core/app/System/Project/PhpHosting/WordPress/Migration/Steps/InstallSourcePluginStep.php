<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigratorDestinationConfig;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginTokenCheck;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;

/**
 * wp_admin: upload the migrator zip through the source wp-admin and activate
 * the plugin, with this import's own token in its config.php instead of the
 * one published with the release, then check how its transfer routes treat a
 * call without that token ({@see SourcePluginTokenCheck}). Login failure fails
 * this step and never starts remote-sync.
 */
final class InstallSourcePluginStep implements MigrationStep
{
    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private ?WordpressMigratorZip $zip = null,
    ) {
        $this->zip ??= new WordpressMigratorZip(WordpressMigratorZip::JOB_DOWNLOAD_SECONDS);
    }

    public function name(): string
    {
        return WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN;
    }

    public function run(WordpressMigration $migration): void
    {
        $client = $this->source->admin($migration);
        $token = $this->sourceToken($migration);

        $package = $this->zip->packageWithToken($token);
        try {
            $this->install($migration, $client, $package, $token);
        } finally {
            @unlink($package);
        }

        $migration->markStepCompleted($this->name());
        WordpressMigratorZip::discard($migration->id);
    }

    /**
     * Kept from an earlier run, so a plugin that run left installed still matches it.
     */
    private function sourceToken(WordpressMigration $migration): string
    {
        $token = MigratorDestinationConfig::tokenFor($migration->secretsForWorker()['source_token']);
        $migration->storeSourceToken($token);

        return $token;
    }

    private function install(WordpressMigration $migration, SourceWordpressClient $client, string $package, string $token): void
    {
        $client->installPluginFromZip($package);
        $client->activatePlugin(SourceWordpressClient::PLUGIN_SLUG);
        $client->assertPluginAcceptsToken($token);
        SourcePluginTokenCheck::run($migration, $client, WordpressMigratorZip::requiresToken($package));
    }
}
