<?php

namespace App\Console\Commands\Backup\Container;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Lib\Backup\ContainerRemoval;
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

        $projects = ContainerRemoval::projectsOnSchedule($container);
        if ($projects !== []) {
            $this->error(ContainerRemoval::scheduleRefusal($projects));

            return 1;
        }

        $backups = $container->backups()->count();
        $hasBackups = $backups > 0;
        if ($hasBackups && ! $this->option('delete-backups')) {
            $this->error(ContainerRemoval::backupsRefusal($backups, '--delete-backups'));

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
                    $this->error("Backup {$backup->id} has no owning project.");

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

        $container->deleteWithEmptyLocation();
        $this->info('Backup container deleted successfully.');

        return 0;
    }
}
