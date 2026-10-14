<?php

namespace App\Console\Commands\Backup\Project;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use Illuminate\Console\Command;

class DownloadCommand extends Command
{
    protected $signature = 'project:backup:download
        {project : Project username}
        {backup : Backup ID}
        {item : Backup item ID}
        {--output= : Write to this path instead of stdout}';

    protected $description = 'Stream one item of a project backup';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $backupId = (int) $this->argument('backup');
        $itemId = (int) $this->argument('item');

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");
            return 1;
        }

        $backup = BackupRecord::query()->find($backupId);
        if ($backup === null || $backup->user_id !== $user->id) {
            $this->error("Backup '{$backupId}' not found for project '{$username}'.");
            return 1;
        }
        if ($backup->backupStatus() !== 'completed') {
            $this->error('Backup is not complete');
            return 1;
        }

        $item = $backup->items->firstWhere('id', $itemId);
        if ($item === null) {
            $this->error("Backup item '{$itemId}' not found.");
            return 1;
        }

        return $this->streamItem($backup, (string) $item->remote_path);
    }

    private function streamItem(BackupRecord $backup, string $remotePath): int
    {
        $storage = $backup->container->storage();
        $source = $storage->readStream($remotePath);
        $destination = $this->openDestination();
        if ($destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            return 1;
        }

        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination) && $destination !== STDOUT) {
                fclose($destination);
            }
        }

        return 0;
    }

    /**
     * @return resource|false
     */
    private function openDestination()
    {
        $output = $this->option('output');
        if (!is_string($output) || $output === '') {
            return STDOUT;
        }

        $handle = @fopen($output, 'wb');
        if ($handle === false) {
            $this->error("Could not open '{$output}' for writing.");
        }

        return $handle;
    }
}
