<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitCommitsRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitCommitsCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:commits
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--branch= : Branch to list commits from}
                            {--limit=50 : Maximum number of commits}';

    protected $description = 'List git commits';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $params = ['path' => $path, 'limit' => (string) $this->option('limit')];
        $branch = (string) ($this->option('branch') ?? '');
        if ($branch !== '') {
            $params['branch'] = $branch;
        }

        return $this->runGit(GitCommitsRequest::class, $params,
            fn (User $user, array $valid) => app(GitActions::class)->commits($user, $valid));
    }
}
