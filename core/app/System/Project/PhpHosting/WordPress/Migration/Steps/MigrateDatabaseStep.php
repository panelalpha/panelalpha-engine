<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchBudget;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\TransferProgress;
use RuntimeException;

/**
 * Step 6: process-batch loop for the database phase, a bounded share per run;
 * the next run continues until the phase is done.
 */
final class MigrateDatabaseStep implements BatchedStep
{
    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private int $batchesPerRun = BatchBudget::BATCHES,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_MIGRATE_DATABASE;
    }

    public function run(WordpressMigration $migration): void
    {
        $pluginMigrationId = $migration->plugin_migration_id;
        if (! is_string($pluginMigrationId) || $pluginMigrationId === '') {
            throw new RuntimeException('Cannot migrate database: plugin_migration_id is missing');
        }

        $finished = (new MigrationTransfer(
            $this->source->open($migration),
            onProgress: fn (TransferProgress $progress) => $migration->storeTransferProgress($this->name(), $progress),
        ))->migrateDatabase($pluginMigrationId, $this->budget($migration));

        if ($finished) {
            $migration->markStepCompleted($this->name());
        }
    }

    private function budget(WordpressMigration $migration): BatchBudget
    {
        return new BatchBudget($this->batchesPerRun, BatchBudget::SECONDS, $migration->cancelRequested(...));
    }
}
