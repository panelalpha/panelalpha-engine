<?php

namespace App\Console\Commands\Backup\Project;

use App\Exceptions\ProblemException;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectRestore;
use App\Models\Backup as BackupRecord;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class RestoreIntoCommand extends Command
{
    protected $signature = 'project:backup:restore-into
        {project : The project whose backup this is}
        {backup : Backup ID}
        {--as= : The new project\'s account name}
        {--domain= : The new project\'s main domain}
        {--force : Skip confirmation}';

    protected $description = 'Restore a project backup into a new project, in-process';

    public function handle(): int
    {
        $newUsername = trim((string) $this->option('as'));
        if ($newUsername === '') {
            $this->error('Pass the new project name with --as.');

            return 1;
        }

        $user = $this->sourceProject();
        $record = $user === null ? null : $this->sourceBackup($user);
        if ($user === null || $record === null) {
            return 1;
        }

        if (! $this->option('force') && ! $this->confirm("Restore backup {$record->id} into a new project '{$newUsername}'?")) {
            $this->info('Cancelled.');

            return 0;
        }

        return $this->restoreInto($user, $record, $newUsername);
    }

    private function sourceProject(): ?User
    {
        $username = (string) $this->argument('project');
        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Project '{$username}' not found.");
        }

        return $user;
    }

    private function sourceBackup(User $user): ?BackupRecord
    {
        $backupId = (int) $this->argument('backup');
        $record = BackupRecord::query()->find($backupId);
        if ($record === null || $record->user_id !== $user->id) {
            $this->error("Backup '{$backupId}' not found for project '{$user->username}'.");

            return null;
        }

        return $record;
    }

    private function restoreInto(User $user, BackupRecord $record, string $newUsername): int
    {
        try {
            $target = app(ProjectRestore::class)->restoreNow($user, $record, $this->newProjectInput($newUsername));
        } catch (ValidationException $e) {
            $this->error(self::reason($e));

            return 1;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info("Backup {$record->id} restored into project '{$target->username}' ({$target->domain}).");

        return 0;
    }

    /**
     * @throws ValidationException
     */
    private function newProjectInput(string $newUsername): NewProjectInput
    {
        $params = [
            'username' => strtolower(trim($newUsername)),
            'template' => 'dind',
        ];
        $domain = $this->option('domain');
        if (is_string($domain) && trim($domain) !== '') {
            $params['domain'] = strtolower(trim($domain));
        }

        return NewProjectInput::fromArray($params);
    }

    private static function reason(ValidationException $e): string
    {
        if ($e instanceof ProblemException && isset($e->problems[0]['message'])) {
            return (string) $e->problems[0]['message'];
        }

        $first = $e->errors();
        $messages = reset($first);

        return is_array($messages) && isset($messages[0]) ? (string) $messages[0] : $e->getMessage();
    }
}
