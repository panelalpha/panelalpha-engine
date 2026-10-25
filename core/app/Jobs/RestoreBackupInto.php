<?php

namespace App\Jobs;

use App\Jobs\Concerns\AttachTask;
use App\Lib\Project\ProjectRestore;
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

class RestoreBackupInto implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(
        public int $backupId,
        public string $targetUsername,
    ) {
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
                throw new RuntimeException('The backup no longer exists.');
            }

            $source = User::query()->find($record->user_id);
            if ($source === null) {
                throw new RuntimeException('Backup user not found');
            }

            $target = User::findByUsername($this->targetUsername);
            if ($target === null) {
                throw new RuntimeException("Project '{$this->targetUsername}' no longer exists.");
            }

            app(ProjectRestore::class)->restore($source, $record, $target);
        });
    }

    public function failed(?Throwable $e): void
    {
        $target = User::findByUsername($this->targetUsername);
        if ($target === null || in_array($target->asyncStatus()['restore'] ?? null, ['completed', 'failed'], true)) {
            return;
        }

        $target->markRestoreFailed($e?->getMessage() ?? 'Restore job failed');
    }
}
