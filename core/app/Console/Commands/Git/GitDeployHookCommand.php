<?php

namespace App\Console\Commands\Git;

use App\Exceptions\NotFoundException;
use App\Http\Requests\Git\DeployHookCreateRequest;
use App\Http\Requests\Git\GitPathRequest;
use App\Lib\DeployHook\DeployHookActions;
use App\Lib\DeployHook\DeployHookNotFound;
use App\Lib\DeployHook\ProviderRegistrationRefused;
use App\Models\User;
use Illuminate\Console\Command;

class GitDeployHookCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:deploy-hook
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or the document root of the main domain (FPM/LiteSpeed))}
                            {--provider= : Git host the hook is for (github); reserved for provider-specific setup instructions}
                            {--register : Also create the webhook at the git provider with the checkout\'s token}
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

        try {
            return $this->dispatchAction($params);
        } catch (ProviderRegistrationRefused $e) {
            $this->error($e->getMessage());
            $this->printCreatedHook($e);

            return self::FAILURE;
        }
    }

    /** A hook created before the refusal is printed: its secret is never shown again. */
    private function printCreatedHook(ProviderRegistrationRefused $e): void
    {
        if ($e->createdHook !== null) {
            $this->line((string) json_encode(['data' => $e->createdHook], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * The create and delete paths print their own JSON; a provider registration
     * refusal is a plain line on stderr and a failed exit, never HTTP-like output.
     *
     * @param  array<string, mixed>  $params
     */
    private function dispatchAction(array $params): int
    {
        if ($this->option('delete')) {
            return $this->delete($params);
        }
        if ($this->option('rotate')) {
            return $this->withHook(fn () => $this->runGit(GitPathRequest::class, $params,
                fn (User $user, array $valid) => app(DeployHookActions::class)->rotate($user, $valid)));
        }

        return $this->create($params);
    }

    /** @param array<string, mixed> $params */
    private function create(array $params): int
    {
        $provider = trim((string) ($this->option('provider') ?? ''));
        if ($provider !== '') {
            $params['provider'] = $provider;
        }
        if ($this->option('register')) {
            $params['register'] = true;
        }

        return $this->runGit(DeployHookCreateRequest::class, $params,
            fn (User $user, array $valid) => app(DeployHookActions::class)->create($user, $valid));
    }

    /** @param array<string, mixed> $params */
    private function delete(array $params): int
    {
        $this->withHook(fn () => $this->runGit(GitPathRequest::class, $params, function (User $user, array $valid) {
            app(DeployHookActions::class)->delete($user, $valid);

            return null;
        }));
        $this->line((string) json_encode(['data' => ['deleted' => true]], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * The endpoint's 404 says only "no deploy hook"; name the checkout and the
     * project here, where the command knows both.
     *
     * @param  callable(): int  $run
     */
    private function withHook(callable $run): int
    {
        try {
            return $run();
        } catch (DeployHookNotFound $e) {
            throw new NotFoundException("Deploy hook not found for checkout '{$e->path}' in project '{$this->argument('username')}'.");
        }
    }
}
