<?php

namespace App\Console\Commands\Git;

use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitUpdateCredentialsCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:update-credentials
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or public_html (FPM/LiteSpeed))}
                            {--token= : Personal access token (omit to leave unchanged; pass empty to clear)}';

    protected $description = 'Update git credentials for a directory';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $params = ['path' => $path];
        if ($this->option('token') !== null) {
            $params['token'] = (string) $this->option('token');
        }

        return $this->runGit(GitUpdateCredentialsRequest::class, $params,
            fn (User $user, array $valid) => app(GitActions::class)->updateCredentials($user, $valid));
    }
}
