<?php

namespace Tests\Unit\DeployHook;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\CheckoutRedeploy;

final class SpyCheckoutRedeploy extends CheckoutRedeploy
{
    /** @var list<array{source: string, commit: ?string, logger: ?DeployLogger}> */
    public array $calls = [];

    /** How often anything asked for a rebuild at all, whether or not the checkout warranted one. */
    public int $requests = 0;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function afterMutation(ProjectGit $git, Project $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        $this->requests++;

        parent::afterMutation($git, $project, $source, $commit, $deployLogger);
    }

    protected function rebuild(Project $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        $this->calls[] = ['source' => $source, 'commit' => $commit, 'logger' => $deployLogger];

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
