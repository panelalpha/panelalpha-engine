<?php

namespace App\Console\Commands\Git;

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
            $exit = $this->dispatchGit('DELETE', '/git/deploy-hook', $params);
            if ($exit === self::SUCCESS) {
                // The API answers 204; say so, in the JSON the other git commands speak.
                $this->line((string) json_encode(['data' => ['deleted' => true]], JSON_THROW_ON_ERROR));
            }

            return $exit;
        }

        if ($this->option('rotate')) {
            return $this->dispatchGit('POST', '/git/deploy-hook/rotate', $params);
        }

        $provider = trim((string) ($this->option('provider') ?? ''));
        if ($provider !== '') {
            $params['provider'] = $provider;
        }

        // Create is idempotent: for a checkout that has a hook this shows it,
        // without the secret.
        return $this->dispatchGit('POST', '/git/deploy-hook', $params);
    }
}
