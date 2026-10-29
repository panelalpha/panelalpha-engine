<?php

namespace App\Console\Commands\Backup\Container;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use Illuminate\Console\Command;

class DeleteCommand extends Command
{
    use ConfirmsChanges;

    protected $signature = 'backup:container:delete {container : Container ID or name}
        {--delete-backups : Delete backups before removing the container}
        {--force : Skip confirmation}';

    protected $description = 'Delete a backup container';

    public function handle(): int
    {
        $containerArg = $this->argument('container');
        if (! is_string($containerArg)) {
            $this->error('Invalid container identifier.');

            return 1;
        }

        $container = BackupContainer::findByIdOrName($containerArg);
        if ($container === null) {
            $this->error("Backup container '{$containerArg}' not found.");

            return 1;
        }

        $hasBackups = $container->backups()->exists();
        if ($hasBackups && ! $this->option('delete-backups')) {
            $this->error('Cannot delete backup container while backups exist.');

            return 1;
        }

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        $this->info('Backup container:');
        $this->line("  ID: {$container->id}");
        $this->line("  Name: {$container->name}");
        $this->line("  Driver: {$container->driver}");
        $this->line("  Location: {$container->location}");

        if (! $this->option('force') && ! $this->confirm('Delete this container?')) {
            $this->info('Cancelled.');

            return 0;
        }

        if ($hasBackups) {
            $backups = $container->backups()->with('container')->get();
            foreach ($backups as $backup) {
                $user = $backup->user;
                if ($user === null) {
                    $this->error("Backup {$backup->id} has no owning user.");

                    return 1;
                }

                try {
                    $user->project()->backup($backup)->delete();
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return 1;
                }

                if (BackupRecord::query()->find($backup->id) !== null) {
                    $backup->refresh();
                    $this->error($backup->error ?? "Failed to delete backup {$backup->id}.");

                    return 1;
                }
            }
        }

        $container->delete();
        $this->info('Backup container deleted successfully.');

        return 0;
    }
}
