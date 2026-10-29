<?php

namespace App\Console\Commands\Backup\Project;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

class CreateCommand extends Command
{
    protected $signature = 'project:backup:create
        {project : Project username}
        {--container= : Backup container id or name}';

    protected $description = 'Create a full project backup in-process';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $containerIdOrName = $this->stringOption('container');

        if ($containerIdOrName === null) {
            $this->error('The --container option is required.');

            return 1;
        }

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");

            return 1;
        }

        $container = BackupContainer::findByIdOrName($containerIdOrName);
        if ($container === null) {
            $this->error("Backup container '{$containerIdOrName}' not found.");

            return 1;
        }

        $backup = BackupRecord::prepare($user, $container, 'artisan');

        try {
            $user->project()->backup($backup)->run();
        } catch (LogicException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (Throwable) {
            $backup->refresh();
        }

        $backup = $backup->fresh(['items']);

        $this->info("Backup created (ID: {$backup->id}, status: {$backup->backupStatus()}).");
        if ($backup->error !== null) {
            $this->line('Error: '.$backup->error);
        }

        return $backup->backupStatus() === 'completed' ? 0 : 1;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
