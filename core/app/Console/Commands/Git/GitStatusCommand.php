<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitStatusRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitStatusCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:status
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--fetch : Fetch from remote before reporting status}';

    protected $description = 'Git repository status';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $params = ['path' => $path];
        if ($this->option('fetch')) {
            $params['fetch'] = '1';
        }

        return $this->runGit(GitStatusRequest::class, $params,
            fn (User $user, array $valid) => app(GitActions::class)
                ->status($user, $valid, filter_var($valid['fetch'] ?? false, FILTER_VALIDATE_BOOLEAN)));
    }
}
