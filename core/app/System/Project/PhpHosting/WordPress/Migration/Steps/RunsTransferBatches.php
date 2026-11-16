<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchBudget;
use App\System\Project\PhpHosting\WordPress\Migration\TransferProgress;
use Closure;

/**
 * The bounded, cancellable share of a transfer one run of a batched step does, and where its progress goes.
 */
trait RunsTransferBatches
{
    private function budget(WordpressMigration $migration): BatchBudget
    {
        return new BatchBudget($this->batchesPerRun, BatchBudget::SECONDS, $migration->cancelRequested(...));
    }

    /**
     * @return Closure(TransferProgress): void
     */
    private function progressRecorder(WordpressMigration $migration): Closure
    {
        return fn (TransferProgress $progress) => $migration->storeTransferProgress($this->name(), $progress);
    }
}
