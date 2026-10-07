<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitConnectRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitConnectCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:connect
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--repo-url= : Remote repository URL}
                            {--branch= : Branch to track}
                            {--token= : Personal access token}
                            {--repair : Reconnect using stored site_git configuration}';

    protected $description = 'Connect a directory to a git remote';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $repair = (bool) $this->option('repair');
        $repoUrl = (string) ($this->option('repo-url') ?? '');
        $branch = (string) ($this->option('branch') ?? '');

        if (!$repair) {
            if ($repoUrl === '') {
                $this->error('--repo-url is required');

                return 1;
            }
            if ($branch === '') {
                $this->error('--branch is required');

                return 1;
            }
        }

        $params = ['path' => $path];
        if ($repair) {
            $params['repair'] = true;
        }
        if ($repoUrl !== '') {
            $params['repo_url'] = $repoUrl;
        }
        if ($branch !== '') {
            $params['branch'] = $branch;
        }
        $token = $this->option('token');
        if ($token !== null) {
            $params['token'] = (string) $token;
        }

        return $this->runGit(GitConnectRequest::class, $params,
            fn (User $user, array $valid) => app(GitActions::class)->connectRemote($user, $valid));
    }
}
