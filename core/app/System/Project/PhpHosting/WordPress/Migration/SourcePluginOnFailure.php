<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use Throwable;

/**
 * A wp_admin import that fails once the migrator plugin is on the source
 * removes it there, so it is installed no longer than the run. Resume then
 * installs it again and restarts the transfer, which a removed plugin cannot
 * continue. When the removal itself fails the plugin stays and resume
 * continues from the failed step.
 */
final class SourcePluginOnFailure implements FailureCleanup
{
    /** The steps that need the plugin on the source, from its install on. */
    public const PLUGIN_STEPS = [
        WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN,
        WordpressMigration::STEP_REMOTE_SYNC,
        WordpressMigration::STEP_MIGRATE_DATABASE,
        WordpressMigration::STEP_MIGRATE_FILES,
        WordpressMigration::STEP_FINALIZE,
    ];

    public function __construct(
        private SourceConnection $source = new SourceConnection,
    ) {}

    public function afterFailure(WordpressMigration $migration, string $failedStep): void
    {
        if ($migration->usesManualPlugin() || ! in_array($failedStep, self::PLUGIN_STEPS, true)) {
            return;
        }

        try {
            $this->source->admin($migration)->deletePlugin(SourceWordpressClient::PLUGIN_SLUG);
        } catch (SourceRequestAborted $e) {
            throw $e;
        } catch (Throwable $e) {
            $migration->appendStepLog(
                $failedStep,
                WordpressMigration::STEP_STATUS_FAILED,
                'The migrator plugin stays on the source site until the import is resumed or cancelled: '
                    .$e->getMessage(),
            );

            return;
        }

        $migration->restartSteps(self::PLUGIN_STEPS);
        $migration->appendStepLog(
            $failedStep,
            WordpressMigration::STEP_STATUS_FAILED,
            'Removed the migrator plugin from the source site; resume installs it again and restarts the transfer.',
        );
    }
}
