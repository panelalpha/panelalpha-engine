<?php

namespace App\Console\Commands\Backup\Project;

use App\Models\BackupContainer;
use App\Models\BackupSchedule;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Console\Command;

class ScheduleCommand extends Command
{
    protected $signature = 'project:backup:schedule
        {project : Project username}
        {--container= : Backup container id or name}
        {--cron= : Five-field cron expression in UTC}
        {--keep= : Completed scheduled backups to keep (1-365)}
        {--disable : Turn the schedule off}';

    protected $description = 'Create, replace or disable the automatic backup schedule of a project';

    public function handle(): int
    {
        $username = (string) $this->argument('project');

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");
            return 1;
        }

        $schedule = BackupSchedule::query()->where('user_id', $user->id)->first();

        if ($this->option('disable')) {
            return $this->disable($schedule);
        }

        return $this->store($user, $schedule);
    }

    private function disable(?BackupSchedule $schedule): int
    {
        if ($schedule === null) {
            $this->info('No schedule to disable.');
            return 0;
        }

        $schedule->enabled = false;
        $schedule->save();
        $this->info('Schedule disabled.');

        return 0;
    }

    private function store(User $user, ?BackupSchedule $schedule): int
    {
        $cron = $this->stringOption('cron');
        if ($cron === null) {
            $this->error('The --cron option is required.');
            return 1;
        }
        if (!CronExpression::isValidExpression($cron)) {
            $this->error('Invalid cron expression.');
            return 1;
        }
        if (!BackupSchedule::isAtMostHourly($cron)) {
            $this->error('Automatic backups may run at most once an hour.');
            return 1;
        }

        $container = $this->resolveContainer($schedule);
        if ($container === null) {
            return 1;
        }

        $keep = $this->resolveKeep($schedule);
        if ($keep === null) {
            return 1;
        }

        /** @var BackupSchedule $schedule */
        $schedule = $schedule ?? new BackupSchedule(['user_id' => $user->id]);
        $schedule->fill([
            'container_id' => $container->id,
            'cron' => $cron,
            'keep_last' => $keep,
            'enabled' => true,
            'last_error' => null,
        ]);
        $schedule->advanceNextRun();
        $schedule->save();

        $this->info("Schedule set (cron: {$cron}, next run: {$schedule->next_run_at?->toIso8601String()}).");

        return 0;
    }

    private function resolveContainer(?BackupSchedule $schedule): ?BackupContainer
    {
        $idOrName = $this->stringOption('container');
        if ($idOrName === null && $schedule !== null) {
            $idOrName = (string) $schedule->container_id;
        }
        if ($idOrName === null) {
            $this->error('The --container option is required for a new schedule.');
            return null;
        }

        $container = BackupContainer::findByIdOrName($idOrName);
        if ($container === null) {
            $this->error("Backup container '{$idOrName}' not found.");
        }

        return $container;
    }

    private function resolveKeep(?BackupSchedule $schedule): ?int
    {
        $value = $this->option('keep');
        if ($value === null) {
            return $schedule?->keep_last ?? 7;
        }
        if (!is_numeric($value) || (int) $value < 1 || (int) $value > 365) {
            $this->error('The --keep option must be between 1 and 365.');
            return null;
        }

        return (int) $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
