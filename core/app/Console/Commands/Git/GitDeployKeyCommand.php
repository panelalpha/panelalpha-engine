<?php

namespace App\Console\Commands\Git;

use App\Exceptions\NotFoundException;
use App\Http\Requests\Git\GitDeployKeyRequest;
use App\Lib\Git\DeployKey;
use App\Lib\Git\GitActions;
use App\Models\User;
use Illuminate\Console\Command;

class GitDeployKeyCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:deploy-key
                            {username : Project username}
                            {--host= : Pin this git host too (host or host:port); github.com, gitlab.com and bitbucket.org are pinned already}
                            {--delete : Delete the deploy key and its pinned host keys}';

    protected $description = 'Create, show or delete the SSH deploy key a project clones SSH remotes with';

    public function handle(): int
    {
        if ($this->option('delete')) {
            $user = User::findByUsernameOrFail((string) $this->argument('username'));
            if (!DeployKey::delete($user)) {
                throw new NotFoundException("Project '{$user->username}' has no deploy key.");
            }
            $this->line((string) json_encode(['data' => ['deleted' => true]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $host = trim((string) ($this->option('host') ?? ''));

        // Idempotent: for a project that has a key this shows it again.
        return $this->runGit(GitDeployKeyRequest::class, $host !== '' ? ['host' => $host] : [],
            fn (User $user, array $valid) => app(GitActions::class)->deployKey($user, $valid));
    }
}
