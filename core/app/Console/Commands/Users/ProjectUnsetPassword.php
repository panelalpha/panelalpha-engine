<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Illuminate\Console\Command;

class ProjectUnsetPassword extends Command
{
    use ResolvesProject;

    protected $aliases = ['projects:unset-password'];

    protected $signature = 'project:unset-password
        {--project= : Project username}
        {--username= : Deprecated alias for --project}
        {--force : Skip confirmation}';

    protected $description = 'Remove site password protection from a project';

    public function handle(): int
    {
        $this->foldProjectOption();

        $username = trim((string) $this->option('username'));
        if ($username === '') {
            $this->error('--project is required.');
            return 1;
        }

        $user = User::findByUsername($username);
        if (!$user) {
            $this->error("Project '{$username}' not found.");
            return 1;
        }

        if (!SitePasswordProtection::isEnabled($user)) {
            $this->info("Project '{$user->username}' has no site password set.");
            return 0;
        }

        if (!$this->option('force')
            && !$this->confirm("Remove site password for project '{$user->username}'?", true)
        ) {
            $this->info('Aborted.');
            return 0;
        }

        SitePasswordProtection::unset($user);
        $this->info("Password protection removed for '{$user->username}'.");

        return 0;
    }
}
