<?php

namespace App\Console\Commands\Git;

use App\Exceptions\NotFoundException;
use App\Http\Requests\Git\DeployHookCreateRequest;
use App\Http\Requests\Git\GitPathRequest;
use App\Lib\DeployHook\DeployHookActions;
use App\Lib\DeployHook\DeployHookNotFound;
use App\Models\User;
use Illuminate\Console\Command;

class GitDeployHookCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:deploy-hook
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or public_html (FPM/LiteSpeed))}
                            {--provider= : Git host the hook is for (github); reserved for provider-specific setup instructions}
                            {--rotate : Replace the hook\'s URL and secret; the old URL stops working}
                            {--delete : Delete the hook and its delivery history}';

    protected $description = 'Create, rotate or delete the push-to-deploy hook of a checkout';

    public function handle(): int
    {
        if ($this->option('rotate') && $this->option('delete')) {
            $this->error('--rotate and --delete cannot be used together.');

            return self::FAILURE;
        }

        $params = ['path' => $this->resolvePath()];

        if ($this->option('delete')) {
            $this->withHook(fn () => $this->runGit(GitPathRequest::class, $params, function (User $user, array $valid) {
                app(DeployHookActions::class)->delete($user, $valid);

                return null;
            }));
            // Deleting returns nothing; say so, in the JSON the other git commands speak.
            $this->line((string) json_encode(['data' => ['deleted' => true]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($this->option('rotate')) {
            return $this->withHook(fn () => $this->runGit(GitPathRequest::class, $params,
                fn (User $user, array $valid) => app(DeployHookActions::class)->rotate($user, $valid)));
        }

        $provider = trim((string) ($this->option('provider') ?? ''));
        if ($provider !== '') {
            $params['provider'] = $provider;
        }

        // Create is idempotent: for a checkout that has a hook this shows it,
        // without the secret.
        return $this->runGit(DeployHookCreateRequest::class, $params,
            fn (User $user, array $valid) => app(DeployHookActions::class)->create($user, $valid));
    }

    /** @param callable(): int $run */
    private function withHook(callable $run): int
    {
        try {
            return $run();
        } catch (DeployHookNotFound $e) {
            // The endpoint's 404 says only "no deploy hook"; name the checkout and project here.
            throw new NotFoundException("Deploy hook not found for checkout '{$e->path}' in project '{$this->argument('username')}'.");
        }
    }
}
