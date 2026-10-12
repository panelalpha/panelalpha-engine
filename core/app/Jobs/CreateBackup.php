<?php

namespace App\Jobs;

use App\Exceptions\DeployAlreadyRunningException;
use App\Jobs\Concerns\AttachTask;
use App\Lib\Project\BackupRetention;
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

class CreateBackup implements ShouldQueue
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
                throw new RuntimeException('The backup no longer exists.');
            }

            $this->runBackup($this->ownerOf($record), $record);

            $record->refresh();
            if ($record->backupStatus() === 'failed') {
                throw new RuntimeException($record->error ?? 'Backup failed');
            }

            app(BackupRetention::class)->prune($record);
        });
    }

    private function ownerOf(BackupRecord $record): User
    {
        $user = User::query()->find($record->user_id);
        if ($user === null) {
            $this->recordFailure($record, 'Backup user not found');
            throw new RuntimeException('Backup user not found');
        }

        return $user;
    }

    /**
     * A deploy, backup or restore already holding the lock fails the row too,
     * or a poller would see it stay `pending`.
     */
    private function runBackup(User $user, BackupRecord $record): void
    {
        try {
            $user->project()->backup($record)->run();
        } catch (DeployAlreadyRunningException $e) {
            $this->recordFailure($record, $e->getMessage());

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function recordFailure(BackupRecord $record, string $error): void
    {
        $record->setBackupStatus('failed');
        $record->error = $error;
        $record->save();
    }

    public function failed(?Throwable $e): void
    {
        $record = BackupRecord::query()->find($this->backupId);
        if ($record !== null && !in_array($record->backupStatus(), ['completed', 'failed'], true)) {
            $this->recordFailure($record, $e?->getMessage() ?? 'Backup job failed');
        }

        $task = $this->task();
        if ($task !== null && !$task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Backup job failed'));
        }
    }
}
