<?php

namespace App\Console\Commands\Backup\Project;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use Illuminate\Console\Command;

class ShowCommand extends Command
{
    protected $signature = 'project:backup:show
        {project : Project username}
        {backup : Backup id}';

    protected $description = 'Show a project backup and its items';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $backupId = (string) $this->argument('backup');

        if (! ctype_digit($backupId)) {
            $this->error('Backup id must be numeric.');

            return 1;
        }

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");

            return 1;
        }

        $backup = BackupRecord::query()
            ->with(['container', 'items'])
            ->find((int) $backupId);

        if ($backup === null || $backup->user_id !== $user->id) {
            $this->error("Backup '{$backupId}' not found for project '{$username}'.");

            return 1;
        }

        $container = $backup->container;
        $containerLabel = $container !== null
            ? $container->name.' (#'.$container->id.')'
            : '#'.$backup->container_id;

        $this->line('Backup ID:   '.$backup->id);
        $this->line('Project:     '.$backup->username);
        $this->line('Container:   '.$containerLabel);
        $this->line('Status:      '.($backup->backupStatus() ?? '-'));
        $this->line('Source:      '.($backup->async_status['source'] ?? '-'));
        $this->line('Created:     '.($backup->created_at?->format('Y-m-d H:i:s') ?? '-'));
        $this->line('Updated:     '.($backup->updated_at?->format('Y-m-d H:i:s') ?? '-'));
        $this->line('Size:        '.$this->formatBytes((int) $backup->items->sum('size_bytes')));
        if ($backup->error !== null) {
            $this->line('Error:       '.$backup->error);
        }

        if ($backup->items->isEmpty()) {
            $this->line('');
            $this->info('No backup items.');

            return 0;
        }

        $this->line('');
        $this->table(
            ['ID', 'Remote path', 'Size', 'Type', 'Name'],
            $backup->items->map(function ($item): array {
                return [
                    $item->id,
                    $item->remote_path,
                    $this->formatBytes((int) $item->size_bytes),
                    $item->details['type'] ?? '-',
                    $item->details['name'] ?? '-',
                ];
            })->all()
        );

        return 0;
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
