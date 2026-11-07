<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Domain;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchBudget;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use RuntimeException;

/**
 * Step 8: confirm the transfer finished, transfer .htaccess, roll it back when the destination HTTP status changes.
 * A transfer still running goes on in the bounded, cancellable shares of the transfer steps.
 */
final class FinalizeStep implements BatchedStep
{
    use LocatesDestinationInstall;
    use RunsTransferBatches;

    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private ?MigrationTransfer $transfer = null,
        private int $batchesPerRun = BatchBudget::BATCHES,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_FINALIZE;
    }

    public function run(WordpressMigration $migration): void
    {
        $pluginMigrationId = $migration->plugin_migration_id;
        if (! is_string($pluginMigrationId) || $pluginMigrationId === '') {
            throw new RuntimeException('Cannot finalize: plugin_migration_id is missing');
        }

        $label = $migration->target_label;
        if (! is_string($label) || $label === '') {
            throw new RuntimeException('Cannot finalize: target_label is missing');
        }

        $destinationUrl = SourceUrl::destinationSiteUrl($label, $migration->source_url);

        $transfer = $this->transfer ?? new MigrationTransfer(
            $this->source->transfer($migration),
            null,
            function (string $_destinationUrl) use ($migration, $label): void {
                $this->removeDestinationHtaccess($migration, $label);
            },
            onProgress: $this->progressRecorder($migration),
        );

        if ($transfer->finalize($migration, $pluginMigrationId, $destinationUrl, $this->budget($migration))) {
            $migration->markStepCompleted($this->name());
        }
    }

    private function removeDestinationHtaccess(WordpressMigration $migration, string $label): void
    {
        $domain = Domain::findByName($label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$label}' not found when rolling back .htaccess");
        }

        $user = $this->destinationUser($domain, $label);
        $path = $this->destinationPath($migration, $user, $domain);
        $htaccessPath = $path.'/.htaccess';

        $files = $user->project()->fileManager();
        try {
            $files->remove($htaccessPath);
        } catch (\Throwable $exception) {
            if ($files->exists($htaccessPath)) {
                throw $exception;
            }
        }
    }
}
