<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchBudget;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\TransferProgress;
use RuntimeException;

/**
 * Step 8: confirm transfer finished, transfer .htaccess, roll it back when
 * destination HTTP status changes. A transfer still running goes on in the
 * bounded, cancellable shares of the transfer steps.
 */
final class FinalizeStep implements BatchedStep
{
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
            onProgress: fn (TransferProgress $progress) => $migration->storeTransferProgress($this->name(), $progress),
        );

        if ($transfer->finalize($migration, $pluginMigrationId, $destinationUrl, $this->budget($migration))) {
            $migration->markStepCompleted($this->name());
        }
    }

    private function budget(WordpressMigration $migration): BatchBudget
    {
        return new BatchBudget($this->batchesPerRun, BatchBudget::SECONDS, $migration->cancelRequested(...));
    }

    private function removeDestinationHtaccess(WordpressMigration $migration, string $label): void
    {
        $domain = Domain::findByName($label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$label}' not found when rolling back .htaccess");
        }

        $user = $domain->user;
        if (! $user instanceof User) {
            throw new RuntimeException("No user for domain '{$label}'");
        }

        $docRoot = $user->project()->homeDirPath().$domain->getDocumentRoot();
        $subdir = SourceUrl::installSubdirectory($migration->source_url);
        $path = $subdir === '' ? $docRoot : $docRoot.'/'.$subdir;
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
