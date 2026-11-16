<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Illuminate\Console\Command;

class ProjectSetPassword extends Command
{
    protected $aliases = ['projects:set-password'];

    protected $signature = 'project:set-password
        {--project= : Project username}
        {--password= : Site password (prompted if omitted)}
        {--force : Skip confirmation}';

    protected $description = 'Enable site password protection for a project (all domains; nginx-proxy)';

    public function handle(): int
    {
        $username = trim((string) $this->option('project'));
        if ($username === '') {
            $this->error('--project is required.');

            return 1;
        }

        $user = User::findByUsername($username);
        if (! $user) {
            $this->error("Project '{$username}' not found.");

            return 1;
        }

        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            $password = $this->secret('Site password');
            if (! is_string($password) || $password === '') {
                $this->error('Password must not be empty.');

                return 1;
            }
            $confirm = $this->secret('Confirm password');
            if ($confirm !== $password) {
                $this->error('Passwords do not match.');

                return 1;
            }
        }

        if (! $this->option('force')
            && ! $this->confirm("Set site password for project '{$user->username}'?", true)
        ) {
            $this->info('Aborted.');

            return 0;
        }

        try {
            SitePasswordProtection::set($user, $password);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $mode = SitePasswordProtection::authMode();
        $this->info("Password protection enabled for '{$user->username}' (mode: {$mode}).");

        return 0;
    }
}
