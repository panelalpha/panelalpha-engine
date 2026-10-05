<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitPullRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitPullCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:pull
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--strategy= : Pull strategy (ff, force or push_first; defaults to ff)}';

    protected $description = 'Pull from the git remote';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $params = ['path' => $path];
        $strategy = (string) ($this->option('strategy') ?? '');
        if ($strategy !== '') {
            $params['strategy'] = $strategy;
        }

        return $this->runGit(GitPullRequest::class, $params,
            fn (User $user, array $valid) => app(GitActions::class)->pull($user, $valid));
    }
}
