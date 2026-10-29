<?php

namespace App\Jobs;

use App\Jobs\Concerns\AttachTask;
use App\Models\Backup as BackupRecord;
use App\Models\User;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class DeleteBackup implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(public int $backupId)
    {
        $this->onQueue('default');
    }

    /**
     * @throws \Exception
     */
    public function handle(): void
    {
        $this->runTask(function (): void {
            $record = BackupRecord::query()->find($this->backupId);
            if ($record === null) {
                return;
            }

            $user = User::query()->find($record->user_id);
            if ($user === null) {
                $this->recordFailure($record, 'Backup user not found');
                throw new RuntimeException('Backup user not found');
            }

            $backupId = $record->id;
            $user->project()->backup($record)->delete();

            if (BackupRecord::query()->find($backupId) !== null) {
                $record->refresh();
                throw new RuntimeException($record->error ?? 'Backup delete failed');
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $record = BackupRecord::query()->find($this->backupId);
        if ($record !== null && ! in_array($record->deleteStatus(), ['completed', 'failed'], true)) {
            $this->recordFailure($record, $e?->getMessage() ?? 'Delete job failed');
        }

        $task = $this->task();
        if ($task !== null && ! $task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Delete job failed'));
        }
    }

    private function recordFailure(BackupRecord $record, string $error): void
    {
        $record->setDeleteStatus('failed');
        $record->error = $error;
        $record->save();
    }
}
