<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitRevertRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitRevertCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:revert
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--ref= : Commit ref to revert to (default HEAD)}';

    protected $description = 'Revert local git changes';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $params = ['path' => $path];
        $ref = (string) ($this->option('ref') ?? '');
        if ($ref !== '') {
            $params['ref'] = $ref;
        }

        return $this->runGit(GitRevertRequest::class, $params,
            fn (User $user, array $valid) => app(GitActions::class)->revert($user, $valid));
    }
}
