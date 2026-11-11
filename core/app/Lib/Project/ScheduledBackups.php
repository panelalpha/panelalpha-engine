<?php

namespace App\Lib\Project;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateBackup;
use App\Models\Backup as BackupRecord;
use App\Models\BackupSchedule;
use App\Models\Task;
use App\Models\User;
use App\System\Projects;
use Illuminate\Support\Carbon;

/** Starts the automatic backups that are due, and says what became of each due schedule. */
class ScheduledBackups
{
    /** @return list<ScheduledBackupRun> */
    public function runDue(): array
    {
        return BackupSchedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', Carbon::now('UTC'))
            ->with(['container', 'user'])
            ->get()
            ->map(fn (BackupSchedule $schedule): ScheduledBackupRun => $this->run($schedule))
            ->values()
            ->all();
    }

    private function run(BackupSchedule $schedule): ScheduledBackupRun
    {
        $user = $schedule->user;
        if ($user === null || $schedule->container === null) {
            $schedule->enabled = false;
            $schedule->last_error = 'Project or backup container no longer exists.';
            $schedule->save();

            return ScheduledBackupRun::disabled($schedule);
        }

        if ($this->projectBusy($user)) {
            return $this->recordBusy($schedule);
        }

        return $this->startBackup($schedule, $user);
    }

    private function recordBusy(BackupSchedule $schedule): ScheduledBackupRun
    {
        if ($schedule->next_run_at !== null && $schedule->next_run_at->lte(Carbon::now('UTC')->subHour())) {
            $schedule->last_error = 'The project stayed busy for an hour; this run was skipped.';
            $schedule->advanceNextRun();
            $schedule->save();

            return ScheduledBackupRun::skipped($schedule);
        }

        return ScheduledBackupRun::waiting($schedule);
    }

    private function startBackup(BackupSchedule $schedule, User $user): ScheduledBackupRun
    {
        $backup = BackupRecord::prepare(
            $user,
            $schedule->container,
            source: 'schedule',
            contents: $schedule->contents(),
            kind: 'scheduled',
        );
        $task = Task::start(
            jobType: CreateBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'backup', 'kind' => $backup->kind],
        );
        $backup->recordTask('backup', $task);
        CreateBackup::dispatch($backup->id)->attachTask($task);

        $schedule->last_run_at = Carbon::now('UTC');
        $schedule->last_backup_id = $backup->id;
        $schedule->last_error = null;
        $schedule->advanceNextRun();
        $schedule->save();

        return ScheduledBackupRun::started($schedule, $backup, $task);
    }

    private function projectBusy(User $user): bool
    {
        try {
            Projects::assertIdle($user);
        } catch (ProjectBusyException|DeployBusyException) {
            return true;
        }

        return false;
    }
}
