<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitPathRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitBranchesCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:branches
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or public_html (FPM/LiteSpeed))}';

    protected $description = 'List git branches';

    public function handle(): int
    {
        $path = $this->resolvePath();

        return $this->runGit(GitPathRequest::class, ['path' => $path],
            fn (User $user, array $params) => app(GitActions::class)->branches($user, $params));
    }
}
