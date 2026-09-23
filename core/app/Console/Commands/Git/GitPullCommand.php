<?php

namespace App\Console\Commands\Git;

use Illuminate\Console\Command;

class GitPullCommand extends Command
{
    use PrintsGitJson;

    protected $signature = 'git:pull
                            {username : Project username}
                            {--path= : Directory path inside the project (defaults to project (DinD) or public_html (FPM/LiteSpeed))}
                            {--strategy= : Pull strategy (ff, force or push_first; defaults to ff)}';

    protected $description = 'Pull from the git remote';

    public function handle(): int
    {
        $path = $this->resolvePath();

        $params = ['path' => $path];
        $strategy = (string) ($this->option('strategy') ?? '');
        if ($strategy !== '') {
            $params['strategy'] = $strategy;
        }

        return $this->dispatchGit('POST', '/git/pull', $params);
    }
}
