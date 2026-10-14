<?php

namespace Tests\Unit\Git;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\CheckoutRedeploy;

/**
 * Records what the redeploy around a git change did, in order. The served
 * tree is kept aside and settled for real on the fake host, as for an app
 * whose one container `app-1` runs: the settle takes the deploy lock, so a
 * deploy left unfinished shows as `settle:skipped`. Only the rebuild is faked.
 */
final class RecordingCheckoutRedeploy extends CheckoutRedeploy
{
    /** @var list<string> */
    public array $events = [];

    /** Runs once the tree is aside, before the git change. */
    public ?\Closure $beforeChange = null;

    public function __construct(private readonly ?\Closure $rebuilds = null)
    {
    }

    public function keepingTheServedTree(ProjectGit $git, Project $project, callable $work): mixed
    {
        $runtime = $project->runtime();
        $this->events[] = 'aside:' . (DeployLogger::isLockedFor($project->model()->username) ? 'locked' : 'unlocked');
        (new CheckoutAside($runtime))->moveAside(['app-1'], copyBack: true);
        try {
            if ($this->beforeChange !== null) {
                ($this->beforeChange)();
            }

            return $work();
        } finally {
            $settled = GenerationSweep::settleIfIdle($runtime);
            $this->events[] = $settled === null ? 'settle:skipped' : 'settle:' . implode(', ', $settled);
        }
    }

    protected function rebuild(Project $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        $this->events[] = "rebuild:{$source}:{$commit}";
        if ($this->rebuilds !== null) {
            ($this->rebuilds)($deployLogger);
        }
    }
}
