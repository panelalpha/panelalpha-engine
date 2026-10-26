<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;

/**
 * wp_admin: upload the migrator zip through the source wp-admin and activate
 * the plugin. Login failure fails this step and never starts remote-sync.
 */
final class InstallSourcePluginStep implements MigrationStep
{
    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private ?WordpressMigratorZip $zip = null,
    ) {
        $this->zip ??= new WordpressMigratorZip;
    }

    public function name(): string
    {
        return WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN;
    }

    public function run(WordpressMigration $migration): void
    {
        $client = $this->source->admin($migration);

        $zipPath = $this->zip->installZipFor($migration->id);
        $client->installPluginFromZip($zipPath);
        $client->activatePlugin(SourceWordpressClient::PLUGIN_SLUG);

        $migration->markStepCompleted($this->name());
        WordpressMigratorZip::discard($migration->id);
    }
}
