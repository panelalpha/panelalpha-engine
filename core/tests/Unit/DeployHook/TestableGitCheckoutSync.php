<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\GitCheckoutSync;
use App\System\Project;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\CheckoutRedeploy;

final class TestableGitCheckoutSync extends GitCheckoutSync
{
    public function __construct(CheckoutRedeploy $redeploy, private readonly ProjectGit $git)
    {
        parent::__construct($redeploy);
    }

    protected function checkout(Project $project, string $pathKey): ProjectGit
    {
        return $this->git;
    }

    protected function forgetWorkerState(): void
    {
    }
}
