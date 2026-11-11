<?php

namespace App\Lib\Project;

use App\Models\Backup as BackupRecord;
use App\Models\BackupSchedule;
use App\Models\Task;

/** One due schedule and its outcome; a started one also carries the backup and the task that takes it. */
final class ScheduledBackupRun
{
    private function __construct(
        public readonly ScheduledBackupOutcome $outcome,
        public readonly BackupSchedule $schedule,
        public readonly ?BackupRecord $backup = null,
        public readonly ?Task $task = null,
    ) {}

    public static function started(BackupSchedule $schedule, BackupRecord $backup, Task $task): self
    {
        return new self(ScheduledBackupOutcome::Started, $schedule, $backup, $task);
    }

    public static function waiting(BackupSchedule $schedule): self
    {
        return new self(ScheduledBackupOutcome::Waiting, $schedule);
    }

    public static function skipped(BackupSchedule $schedule): self
    {
        return new self(ScheduledBackupOutcome::Skipped, $schedule);
    }

    public static function disabled(BackupSchedule $schedule): self
    {
        return new self(ScheduledBackupOutcome::Disabled, $schedule);
    }
}
