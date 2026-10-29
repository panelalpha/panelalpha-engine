<?php

namespace App\Console\Commands\Backup\Project;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    protected $signature = 'project:backup:list {project : Project username}';

    protected $description = 'List backups for a project';

    public function handle(): int
    {
        $username = (string) $this->argument('project');

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");

            return 1;
        }

        $backups = BackupRecord::query()
            ->where('user_id', $user->id)
            ->with(['container', 'items'])
            ->orderByDesc('created_at')
            ->get();

        if ($backups->isEmpty()) {
            $this->info("No backups for project '{$username}'.");

            return 0;
        }

        $this->table(
            ['ID', 'Container', 'Status', 'Created', 'Size', 'Error'],
            $backups->map(function (BackupRecord $backup): array {
                $container = $backup->container;
                $containerLabel = $container !== null
                    ? $container->name.' (#'.$container->id.')'
                    : '#'.$backup->container_id;

                return [
                    $backup->id,
                    $containerLabel,
                    $backup->backupStatus() ?? '-',
                    $backup->created_at?->format('Y-m-d H:i:s') ?? '-',
                    $this->formatBytes($this->totalSizeBytes($backup)),
                    $backup->error ?? '-',
                ];
            })->all()
        );

        return 0;
    }

    private function totalSizeBytes(BackupRecord $backup): int
    {
        return (int) $backup->items->sum('size_bytes');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
