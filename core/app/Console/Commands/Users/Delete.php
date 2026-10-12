<?php

namespace App\Console\Commands\Users;

use App\Exceptions\ProjectBusyException;
use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Delete extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:delete', 'users:delete'];

    protected $signature = 'project:delete {project?* : One or more project usernames} {--all : Every project on this engine} {--force : Skip confirmation prompts}';

    protected $description = 'Delete projects entirely: database rows, server config files and home directory';

    /**
     * Without a terminal confirm() answers no, so a non-interactive run needs
     * --force. Exits non-zero when anything was left behind, so uninstall.sh
     * never reports a clean removal over a half-deleted account.
     */
    public function handle(): int
    {
        $usernames = $this->argument('project');
        $forced = (bool) $this->option('force');

        if (!$forced && !$this->input->isInteractive()) {
            $this->error('Deleting without confirmation requires --force.');
            return 1;
        }

        if ($this->option('all')) {
            if ($usernames !== []) {
                $this->error('Pass project names or --all, not both.');
                return 1;
            }

            $usernames = array_map(static fn (User $user): string => (string) $user->username, User::getAll());
            if ($usernames === []) {
                $this->info('No projects on this engine.');
                return 0;
            }

            if (!$this->confirmAll(count($usernames))) {
                return 0;
            }
            $forced = true;
        }

        if ($usernames === []) {
            $this->error('Name at least one project, or pass --all.');
            return 1;
        }

        $failed = false;
        foreach ($usernames as $username) {
            $failed = !$this->deleteProject($username, $forced) || $failed;
        }

        return $failed ? 1 : 0;
    }

    /**
     * One question for the whole set rather than one per project: an operator
     * answering "yes" forty times stopped reading at three (uninstall.sh).
     */
    private function confirmAll(int $count): bool
    {
        return $this->option('force')
            || $this->confirm("All {$count} projects will be completely deleted from this server, are you sure?");
    }

    /** Delete one project after confirming; false when it could not be deleted. */
    private function deleteProject(string $username, bool $forced): bool
    {
        $user = User::findByUsername($username);
        if (!$user) {
            $this->error("User `{$username}` not found in database.");
            return false;
        }

        if (!$forced && !$this->confirm("User `{$username}` will be completely deleted from server, are you sure?")) {
            return true;
        }

        try {
            (new ProjectDeleter())->delete($user);
            $this->info("User `{$username}` deleted.");

            return true;
        } catch (ProjectBusyException $e) {
            $this->error($e->plainMessage());

            return false;
        } catch (\Throwable $e) {
            Log::warning(
                "Could not delete user '{$user->username}': " . $e->getMessage(),
                ['exception' => $e],
            );
            $this->error("ERROR deleting user `{$username}`: " . $e->getMessage());

            return false;
        }
    }
}
