<?php

namespace App\Console\Commands\Backup\Project;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Models\Backup as BackupRecord;
use App\Models\User;
use Illuminate\Console\Command;

class DeleteCommand extends Command
{
    use ConfirmsChanges;

    protected $signature = 'project:backup:delete
        {project : Project username}
        {backup : Backup ID}
        {--force : Skip confirmation}';

    protected $description = 'Delete a project backup in-process';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $backupId = (int) $this->argument('backup');

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");

            return 1;
        }

        $record = BackupRecord::query()->find($backupId);
        if ($record === null || $record->user_id !== $user->id) {
            $this->error("Backup '{$backupId}' not found for project '{$username}'.");

            return 1;
        }

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        if (! $this->option('force') && ! $this->confirm('Delete this backup?')) {
            $this->info('Cancelled.');

            return 0;
        }

        try {
            $user->project()->backup($record)->delete();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if (BackupRecord::query()->find($backupId) !== null) {
            $record->refresh();
            $this->error($record->error ?? 'Backup delete failed.');

            return 1;
        }

        $this->info('Backup deleted successfully.');

        return 0;
    }
}
