<?php

namespace App\Console\Commands\Backup\Project;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use Illuminate\Console\Command;
use LogicException;

class RestoreCommand extends Command
{
    protected $signature = 'project:backup:restore
        {project : Project username}
        {backup : Backup ID}
        {--only-files : Restore project files only}
        {--only-volumes= : Comma-separated volume names to restore}
        {--only-databases= : Comma-separated database names to restore}
        {--exclude-files : Exclude project files from restore}
        {--exclude-volumes= : Comma-separated volume names to exclude}
        {--exclude-databases= : Comma-separated database names to exclude}
        {--force : Skip confirmation}';

    protected $description = 'Restore a project backup in-process';

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

        $only = $this->buildOnlyFilter();
        $exclude = $this->buildExcludeFilter();
        if ($this->restoreFilterNonEmpty($only) && $this->restoreFilterNonEmpty($exclude)) {
            $this->error('Cannot combine include and exclude');

            return 1;
        }

        if (! $this->option('force') && ! $this->confirm('Restore this backup?')) {
            $this->info('Cancelled.');

            return 0;
        }

        try {
            $user->project()->backup($record)->restore($only, $exclude);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (LogicException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $record->refresh();
        $this->info("Restore finished (status: {$record->restoreStatus()}).");
        if ($record->error !== null) {
            $this->line('Error: '.$record->error);
        }

        return $record->restoreStatus() === 'completed' ? 0 : 1;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function restoreFilterNonEmpty(array $filter): bool
    {
        if (($filter['files'] ?? false) === true) {
            return true;
        }

        if (($filter['volumes'] ?? []) !== []) {
            return true;
        }

        if (($filter['databases'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOnlyFilter(): array
    {
        return [
            'files' => (bool) $this->option('only-files'),
            'volumes' => $this->csvOption('only-volumes'),
            'databases' => $this->csvOption('only-databases'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExcludeFilter(): array
    {
        return [
            'files' => (bool) $this->option('exclude-files'),
            'volumes' => $this->csvOption('exclude-volumes'),
            'databases' => $this->csvOption('exclude-databases'),
        ];
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
    }
}
