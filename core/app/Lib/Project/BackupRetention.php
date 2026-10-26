<?php

namespace App\Lib\Project;

use App\Jobs\DeleteBackup;
use App\Models\Backup as BackupRecord;
use App\Models\BackupSchedule;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Keeps a project's automatic backups bounded by its schedule's `keep_last`.
 * Manual backups are never touched; a failed scheduled backup is pruned once it
 * is older than the newest kept completed one.
 */
class BackupRetention
{
    public function prune(BackupRecord $record): void
    {
        if (! $record->isScheduled()) {
            return;
        }

        $schedule = BackupSchedule::query()->where('user_id', $record->user_id)->first();
        if ($schedule === null) {
            return;
        }

        $keep = max(1, (int) $schedule->keep_last);
        $completed = $this->scheduledWithStatus($record->user_id, 'completed');
        $kept = $completed->take($keep)->last();

        $failed = $this->scheduledWithStatus($record->user_id, 'failed')
            ->filter(fn (BackupRecord $backup): bool => $kept !== null
                && $backup->created_at !== null
                && $backup->created_at->lt($kept->created_at));

        foreach ($completed->slice($keep)->concat($failed) as $backup) {
            $this->delete($backup);
        }
    }

    /**
     * @return Collection<int, BackupRecord>
     */
    private function scheduledWithStatus(int $userId, string $status): Collection
    {
        return BackupRecord::query()
            ->where('user_id', $userId)
            ->where('kind', 'scheduled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (BackupRecord $backup): bool => $backup->backupStatus() === $status)
            ->values();
    }

    private function delete(BackupRecord $backup): void
    {
        $backup->setDeleteStatus('pending');
        $backup->error = null;
        $backup->save();

        $task = Task::start(
            jobType: DeleteBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'delete'],
        );
        DeleteBackup::dispatch($backup->id)->attachTask($task);
    }
}
