<?php

namespace App\Console\Commands\Backup;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateBackup;
use App\Lib\Project\ProjectRebuild;
use App\Models\Backup as BackupRecord;
use App\Models\BackupSchedule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunScheduledCommand extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = 'Run the automatic project backups that are due';

    public function handle(): int
    {
        $due = BackupSchedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', Carbon::now('UTC'))
            ->with(['container', 'user'])
            ->get();

        foreach ($due as $schedule) {
            $this->runSchedule($schedule);
        }

        return self::SUCCESS;
    }

    private function runSchedule(BackupSchedule $schedule): void
    {
        $user = $schedule->user;
        if ($user === null || $schedule->container === null) {
            $schedule->enabled = false;
            $schedule->last_error = 'Project or backup container no longer exists.';
            $schedule->save();

            return;
        }

        if ($this->projectBusy($user)) {
            $this->recordBusy($schedule);

            return;
        }

        $this->dispatchBackup($schedule, $user);
    }

    private function recordBusy(BackupSchedule $schedule): void
    {
        if ($schedule->next_run_at !== null && $schedule->next_run_at->lte(Carbon::now('UTC')->subHour())) {
            $schedule->last_error = 'The project stayed busy for an hour; this run was skipped.';
            $schedule->advanceNextRun();
            $schedule->save();
        }
    }

    private function dispatchBackup(BackupSchedule $schedule, User $user): void
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
        CreateBackup::dispatch($backup->id)->attachTask($task);

        $schedule->last_run_at = Carbon::now('UTC');
        $schedule->last_backup_id = $backup->id;
        $schedule->last_error = null;
        $schedule->advanceNextRun();
        $schedule->save();
    }

    private function projectBusy(User $user): bool
    {
        try {
            app(ProjectRebuild::class)->assertIdle($user);
        } catch (ProjectBusyException|DeployBusyException) {
            return true;
        }

        return false;
    }
}
