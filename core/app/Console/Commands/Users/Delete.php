<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Delete extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:delete', 'users:delete'];

    protected $signature = 'project:delete {project?* : One or more project usernames} {--all : Every project on this engine} {--force : Skip confirmation prompts}';

    protected $description = 'Delete projects entirely: database rows, server config files and home directory';

    public function handle(): int
    {
        /** @var array<string> */
        $usernames = $this->argument('project');
        $forced = (bool) $this->option('force');

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

            // One question for the whole set rather than one per project: an
            // operator answering "yes" forty times is an operator who stopped
            // reading at three. uninstall.sh is the caller that matters here.
            $count = count($usernames);
            if (!$this->option('force')
                && !$this->confirm("All {$count} projects will be completely deleted from this server, are you sure?")
            ) {
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
            $user = User::findByUsername($username);
            if (!$user) {
                $this->error("User `{$username}` not found in database.");
                $failed = true;
                continue;
            }
            $shouldDelete = $forced || $this->confirm("User `{$username}` will be completely deleted from server, are you sure?");
            
            if ($shouldDelete) {
                try{
                    $user->project()->destroy();
                    $this->info("User `{$username}` deleted.");
                } catch (\Exception $e) {
                    Log::warning(
                        "Could not delete user '{$user->username}': " . $e->getMessage(),
                        ['exception' => $e],
                    );
                    $this->error("ERROR deleting user `{$username}`: " . $e->getMessage());
                    $failed = true;
                }
            }
        }

        // Non-zero when something was left behind, so uninstall.sh can say so
        // rather than reporting a clean removal over a half-deleted account.
        return $failed ? 1 : 0;
    }
}
