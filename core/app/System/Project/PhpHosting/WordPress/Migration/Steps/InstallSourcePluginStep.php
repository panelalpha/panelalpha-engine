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
 * wp_admin: upload and activate the migrator zip with this import's own token, then check its routes without it
 * ({@see SourcePluginTokenCheck}). A copy already there is recorded first, so the cleanups leave it alone.
 * When wp-admin shows no version for the installed plugin, the import goes on with a {@see VERSION_UNKNOWN_WARNING}.
 */
final class InstallSourcePluginStep implements MigrationStep
{
    public const VERSION_UNKNOWN_WARNING = 'plugin_version_unknown';

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
        $migration->recordSourcePluginPreinstalled($client->hasPlugin(SourceWordpressClient::PLUGIN_SLUG));
        $shownVersion = $client->installPluginFromZip($package);
        $this->recordVersionCheck($migration, $shownVersion, $package);
        $client->activatePlugin(SourceWordpressClient::PLUGIN_SLUG);
        $client->assertPluginAcceptsToken($token);
        SourcePluginTokenCheck::run($migration, $client, WordpressMigratorZip::requiresToken($package));
    }

    /**
     * A version wp-admin shows is compared by the client already; when it shows none, say so in the status, and
     * drop an earlier note once a run reads the version.
     */
    private function recordVersionCheck(WordpressMigration $migration, ?string $shownVersion, string $package): void
    {
        if ($shownVersion !== null) {
            $migration->removeWarning(self::VERSION_UNKNOWN_WARNING);

            return;
        }

        $pinned = WordpressMigratorZip::versionOf($package) ?? 'the pinned release';
        $migration->addWarning(
            self::VERSION_UNKNOWN_WARNING,
            'wp-admin on the source site does not show which version of the wordpress-migrator plugin is installed, '
            .'so the Engine could not check that it is '.$pinned.'. If the import fails with an undefined method or '
            .'class, remove every wordpress-migrator and panelalpha-migrator copy from the source site and run the '
            .'import again.',
        );
    }
}
