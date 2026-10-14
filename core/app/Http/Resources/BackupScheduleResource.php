<?php

namespace App\Http\Resources;

use App\Models\BackupSchedule;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupScheduleResource extends JsonResource
{
    public function toArray($request)
    {
        $schedule = $this->resource;
        assert($schedule instanceof BackupSchedule);

        return [
            'container_id' => $schedule->container_id,
            'cron' => $schedule->cron,
            'keep_last' => $schedule->keep_last,
            'files' => (bool) $schedule->files,
            'databases' => (bool) $schedule->databases,
            'enabled' => (bool) $schedule->enabled,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'last_backup_id' => $schedule->last_backup_id,
            'last_error' => $schedule->last_error,
        ];
    }
}
