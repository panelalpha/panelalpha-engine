<?php

namespace App\Lib\DeployHook;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\CheckoutRedeploy;

/**
 * A Deploy-managed checkout is force-pulled, the repository being the truth, and rebuilt (ADR-0001 keeps our files);
 * a Site Git checkout is only fast-forwarded, since `clean -fd` would delete the files the server owns there.
 */
class GitCheckoutSync implements CheckoutSync
{
    public function __construct(private readonly CheckoutRedeploy $redeploy) {}

    public function pullAndRebuild(User $user, string $pathKey, ?string $pushedCommit, ?callable $onDeployStarted = null): void
    {
        $project = $user->project();
        $git = $this->checkout($project, $pathKey);

        if (! $git->isDeployManaged()) {
            $git->pull(ProjectGit::STRATEGY_FF);

            return;
        }

        $deployLogger = $this->startDeployBeforeThePull($user, $onDeployStarted);
        $this->startTheAccountBeforeThePull($git, $project, $deployLogger);

        $this->redeploy->keepingTheServedTree(
            $git,
            $project,
            fn () => $this->forcePullAndRebuild($git, $project, $pushedCommit, $deployLogger),
        );
    }

    /**
     * The deploy, and with it the account's lock, starts before the forced pull: `reset --hard`
     * and `clean -fd` rewrite the files a running deploy builds from, so one that is running throws here.
     *
     * @throws DeployAlreadyRunningException
     */
    private function startDeployBeforeThePull(User $user, ?callable $onDeployStarted): ?DeployLogger
    {
        $deployLogger = $this->startDeploy($user);
        if ($deployLogger !== null && $onDeployStarted !== null) {
            $onDeployStarted($deployLogger->getDeployId());
        }

        return $deployLogger;
    }

    /**
     * Git runs in the account's container, so a stopped account is started first, and before the served tree is kept
     * aside, which only a running app has; a failed start has ended the deploy with its own error.
     */
    private function startTheAccountBeforeThePull(ProjectGit $git, ProjectAggregate $project, ?DeployLogger $deployLogger): void
    {
        try {
            $this->redeploy->startAccountIfStopped($git, $project, $deployLogger);
        } catch (\Throwable $e) {
            $this->finishIfUnfinished($deployLogger, DeployLogger::STATUS_FAILED, 'Could not start the account: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * Rebuilds from what the checkout holds after the pull, which differs from the pushed commit when another
     * push landed in between; a pull that fails never reaches the rebuild, so it fails the deploy here.
     */
    private function forcePullAndRebuild(ProjectGit $git, ProjectAggregate $project, ?string $pushedCommit, ?DeployLogger $deployLogger): void
    {
        try {
            $git->pull(ProjectGit::STRATEGY_FORCE);
            $commit = $git->readHeadCommit() ?? $pushedCommit;

            $this->forgetWorkerState();
            $this->redeploy->afterMutation($git, $project, 'push', $commit, $deployLogger);
        } catch (\Throwable $e) {
            $this->finishIfUnfinished($deployLogger, DeployLogger::STATUS_FAILED, 'Could not update the checkout to the pushed branch: '.$e->getMessage());

            throw $e;
        }

        $this->finishIfUnfinished($deployLogger, DeployLogger::STATUS_SUCCESS);
    }

    /**
     * Finishes a deploy the rebuild left open (a runtime with nothing to rebuild returns without finishing).
     * Not only `running`: a cancel sets `cancelled` without finishing, and its lock keeps the tree aside.
     */
    private function finishIfUnfinished(?DeployLogger $deployLogger, string $status, ?string $error = null): void
    {
        if ($deployLogger !== null && ! $deployLogger->isFinished()) {
            $deployLogger->finish($status, $error);
        }
    }

    /**
     * Always a new deploy: resuming one a kill left `running` in `latest.json` would append this push to the
     * dead deploy's log. A live deploy holds the lock, and start() throws on that as resume did.
     *
     * @throws DeployAlreadyRunningException
     */
    protected function startDeploy(User $user): ?DeployLogger
    {
        return DeployLogger::startSafely($user->username);
    }

    protected function checkout(ProjectAggregate $project, string $pathKey): ProjectGit
    {
        return $project->git($pathKey);
    }

    /**
     * A queue worker outlives its jobs, and these two keep the previous deploy's `stages` and `recipe` until
     * reset; a push carries neither, so it must not inherit them.
     */
    protected function forgetWorkerState(): void
    {
        app(DeployPlanContext::class)->set(null);
        app(RecipeChoiceContext::class)->set(null);
    }
}
