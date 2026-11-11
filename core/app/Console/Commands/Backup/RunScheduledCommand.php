<?php

namespace App\Console\Commands\Backup;

use App\Lib\Project\ScheduledBackupOutcome;
use App\Lib\Project\ScheduledBackupRun;
use App\Lib\Project\ScheduledBackups;
use Illuminate\Console\Command;

class RunScheduledCommand extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = 'Run the automatic project backups that are due';

    public function handle(ScheduledBackups $scheduled): int
    {
        $runs = $scheduled->runDue();
        if ($runs === []) {
            $this->line('No automatic backup is due.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $this->line($this->describe($run));
        }

        return self::SUCCESS;
    }

    private function describe(ScheduledBackupRun $run): string
    {
        $schedule = $run->schedule;
        $project = $schedule->user->username ?? "schedule {$schedule->id}";

        return $project.': '.match ($run->outcome) {
            ScheduledBackupOutcome::Started => "backup {$run->backup?->id} queued to {$schedule->container?->name} (task {$run->task?->id}).",
            ScheduledBackupOutcome::Waiting => 'the project is busy; the next run tries again.',
            ScheduledBackupOutcome::Skipped => 'the project stayed busy for an hour; this run is skipped, the next is at '.$schedule->next_run_at?->toIso8601String().'.',
            ScheduledBackupOutcome::Disabled => 'schedule disabled, its project or backup container no longer exists.',
        };
    }
}
