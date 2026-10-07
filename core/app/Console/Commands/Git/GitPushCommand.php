<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitPathRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitPushCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:push
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}';

    protected $description = 'Push local git changes';

    public function handle(): int
    {
        $path = $this->resolvePath();

        return $this->runGit(GitPathRequest::class, ['path' => $path],
            fn (User $user, array $params) => app(GitActions::class)->push($user, $params));
    }
}
