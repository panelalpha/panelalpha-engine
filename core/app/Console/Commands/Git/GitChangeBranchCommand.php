<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitChangeBranchRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitChangeBranchCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:change-branch
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--branch= : Branch to switch to}';

    protected $description = 'Change the tracked git branch';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $branch = (string) ($this->option('branch') ?? '');
        if ($branch === '') {
            $this->error('--branch is required');

            return 1;
        }

        return $this->runGit(GitChangeBranchRequest::class, [
            'path' => $path,
            'branch' => $branch,
        ], fn (User $user, array $params) => app(GitActions::class)->changeBranch($user, $params));
    }
}
