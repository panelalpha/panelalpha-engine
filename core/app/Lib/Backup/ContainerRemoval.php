<?php

namespace App\Lib\Backup;

use App\Models\BackupContainer;
use App\Models\BackupSchedule;

/** What keeps a backup store from being deleted, worded the same over REST and the CLI. */
final class ContainerRemoval
{
    public const IN_USE = 'container_in_use';

    public const HAS_BACKUPS = 'container_has_backups';

    /**
     * The projects whose enabled schedule backs up into the store, by name.
     *
     * @return list<string>
     */
    public static function projectsOnSchedule(BackupContainer $container): array
    {
        $names = [];
        $schedules = BackupSchedule::query()
            ->where('container_id', $container->id)
            ->where('enabled', true)
            ->with('user')
            ->get();
        foreach ($schedules as $schedule) {
            $name = $schedule->user?->username;
            if ($name !== null) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** @param  list<string>  $projects */
    public static function scheduleRefusal(array $projects): string
    {
        return "Cannot delete backup container while a project's automatic backup schedule uses it: "
            .self::namedProjects($projects).'. Disable or delete those schedules first.';
    }

    public static function backupsRefusal(int $count, string $deleteFlag): string
    {
        $backups = $count === 1 ? '1 backup' : "{$count} backups";

        return "Cannot delete backup container while it holds {$backups}. Pass {$deleteFlag} to remove them with the container.";
    }

    /** @param  list<string>  $projects */
    private static function namedProjects(array $projects): string
    {
        $shown = array_slice($projects, 0, 3);
        $left = count($projects) - count($shown);
        if ($left > 0) {
            return implode(', ', $shown)." and {$left} more";
        }

        return implode(', ', $shown);
    }
}
