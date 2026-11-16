<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchBudget;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use RuntimeException;

/**
 * Step 7: process-batch loop for the files phase, a bounded share per run;
 * the next run continues until the phase is done.
 */
final class MigrateFilesStep implements BatchedStep
{
    use RunsTransferBatches;

    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private int $batchesPerRun = BatchBudget::BATCHES,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_MIGRATE_FILES;
    }

    public function run(WordpressMigration $migration): void
    {
        $pluginMigrationId = $migration->plugin_migration_id;
        if (! is_string($pluginMigrationId) || $pluginMigrationId === '') {
            throw new RuntimeException('Cannot migrate files: plugin_migration_id is missing');
        }

        $finished = (new MigrationTransfer(
            $this->source->transfer($migration),
            onProgress: $this->progressRecorder($migration),
        ))->migrateFiles($pluginMigrationId, $this->budget($migration));

        if ($finished) {
            $migration->markStepCompleted($this->name());
        }
    }
}
