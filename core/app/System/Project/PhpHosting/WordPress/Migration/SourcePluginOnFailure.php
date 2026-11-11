<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use Throwable;

/**
 * A failing wp_admin import removes the plugin this run installed on the source; resume reinstalls it and restarts
 * the transfer, or goes on from the step when the removal failed. Any other copy is left alone.
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
        if ($migration->keepsSourcePlugin() || ! self::installedItsOwnPlugin($migration, $failedStep)) {
            return;
        }

        try {
            $removed = $this->source->admin($migration)->deletePlugin(SourceWordpressClient::PLUGIN_SLUG);
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

        if (! $removed && $failedStep === WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN) {
            return;
        }

        $migration->restartSteps(self::PLUGIN_STEPS);
        $migration->appendStepLog(
            $failedStep,
            WordpressMigration::STEP_STATUS_FAILED,
            'Removed the migrator plugin from the source site; resume installs it again and restarts the transfer.',
        );
    }

    /**
     * A step after install_source_plugin runs only once this run's own plugin reached the source. At that step itself,
     * a copy is this run's only when the source had none before; without that record it may be the operator's.
     */
    private static function installedItsOwnPlugin(WordpressMigration $migration, string $failedStep): bool
    {
        if (! in_array($failedStep, self::PLUGIN_STEPS, true)) {
            return false;
        }

        return $failedStep !== WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN
            || $migration->source_plugin_preinstalled === false;
    }
}
