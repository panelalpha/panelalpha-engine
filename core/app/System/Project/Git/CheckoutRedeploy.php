<?php

namespace App\System\Project\Git;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Git as ProjectGit;

/**
 * Rebuilds a deploy-managed app from the files already in ~/project after a mutating git call: outside
 * {@see ProjectGit}, which talks to git, not DinD, and never by re-cloning, which would undo the pull.
 */
class CheckoutRedeploy
{
    /** The branch the checkout is on, named in the deploy log the rebuild opens. */
    protected ?string $branch = null;

    /**
     * @param  string  $source  what the deploy log says started it (`git` for a manual porcelain call, `push` for a Deploy Hook)
     * @param  ?string  $commit  the commit the checkout is at, named in the log when known
     * @param  ?DeployLogger  $deployLogger  a deploy the caller already started -- and holds the lock
     *                                       of -- before mutating the checkout; null starts one here
     */
    public function afterMutation(ProjectGit $git, ProjectAggregate $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        if (! $git->isDeployManaged()) {
            return;
        }

        $this->branch = $git->readHeadBranch();
        $this->rebuild($project, $source, $commit, $deployLogger);
    }

    /**
     * Starts the account of a Deploy-managed checkout when it is not running, as the rebuild would: git runs in the
     * account's container, so a change made before the rebuild needs it up. A start that fails fails the deploy.
     *
     * @param  ?DeployLogger  $deployLogger  the deploy the change and its rebuild run under
     */
    public function startAccountIfStopped(ProjectGit $git, ProjectAggregate $project, ?DeployLogger $deployLogger): void
    {
        if ($git->isDeployManaged()) {
            $this->startAccount($project, $deployLogger);
        }
    }

    /**
     * Runs a change to a Deploy-managed checkout and its redeploy with the served tree kept aside, so an app
     * mounting the checkout never reads files that have not passed the health check.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function keepingTheServedTree(ProjectGit $git, ProjectAggregate $project, callable $work): mixed
    {
        $runtime = $git->isDeployManaged() ? $project->runtime() : null;
        $kept = $runtime instanceof Dind && (new CheckoutAside($runtime))->keepForRunningApp() !== null;
        try {
            return $work();
        } finally {
            if ($kept) {
                GenerationSweep::settleIfIdle($runtime);
            }
        }
    }

    protected function startAccount(ProjectAggregate $project, ?DeployLogger $deployLogger): void
    {
        $runtime = $project->runtime();
        if ($runtime instanceof Dind) {
            $runtime->deployment()->startAccountForCheckout($deployLogger);
        }
    }

    /** rebuildFromCheckout() records its own verdict, and a partial one is not turned into a success here. */
    protected function rebuild(ProjectAggregate $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        $runtime = $project->runtime();
        if (! $runtime instanceof Dind) {
            return;
        }

        $runtime->deployment()->rebuildFromCheckout($deployLogger, $source, $commit, $this->branch);
        $project->system()->webserver()->rebuildDomains();

        $user = $project->model();
        if (in_array($user->getDeploymentStatus(), ['success', 'partial'], true)) {
            return;
        }
        $user->markDeploySucceeded();
        if ($user->exists) {
            $user->save();
        }
    }
}
