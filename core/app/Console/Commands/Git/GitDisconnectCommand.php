<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitPathRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitDisconnectCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:disconnect
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or public_html (FPM/LiteSpeed))}';

    protected $description = 'Disconnect git from a directory';

    public function handle(): int
    {
        $path = $this->resolvePath();

        return $this->runGit(GitPathRequest::class, ['path' => $path],
            fn (User $user, array $params) => app(GitActions::class)->disconnect($user, $params));
    }
}
