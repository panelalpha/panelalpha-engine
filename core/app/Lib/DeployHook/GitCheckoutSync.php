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
 * What a push does to a checkout, which depends on what the checkout is.
 *
 * A Deploy-managed checkout gets a forced pull, then a Checkout Rebuild -- the
 * two things a manual `git/pull` with `force` does, minus the request around
 * them. Forced because the repository is the source of truth once a hook
 * exists: a fast-forward would refuse the moment anything on the checkout
 * diverged, and a push is not the moment to stop and ask. The engine's own
 * files survive that (`clean -fd` spares what `.git/info/exclude` lists; see
 * docs/internal/adr/0001-checkout-mirrors-the-client-repository.md).
 *
 * A Site Git checkout (`public_html` of a WordPress project) is the opposite:
 * the server owns files the repository knows nothing about -- uploads, caches,
 * a config edited in the admin -- and `clean -fd` would delete them. It is
 * fast-forwarded, which leaves whatever git does not need to overwrite alone
 * and refuses, changing nothing, when it does. There is no build to run.
 */
class GitCheckoutSync implements CheckoutSync
{
    public function __construct(private readonly CheckoutRedeploy $redeploy)
    {
    }

    public function pullAndRebuild(User $user, string $pathKey, ?string $pushedCommit, ?callable $onDeployStarted = null): void
    {
        $project = $user->project();
        $git = $this->checkout($project, $pathKey);

        if (!$git->isDeployManaged()) {
            $git->pull(ProjectGit::STRATEGY_FF);

            return;
        }

        $deployLogger = $this->startDeployBeforeThePull($user, $onDeployStarted);

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
     * Rebuilds from what the checkout holds after the pull rather than what the push named; the two
     * differ when another push landed in between. A pull that fails never reaches the rebuild, so it
     * fails the deploy here.
     */
    private function forcePullAndRebuild(ProjectGit $git, ProjectAggregate $project, ?string $pushedCommit, ?DeployLogger $deployLogger): void
    {
        try {
            $git->pull(ProjectGit::STRATEGY_FORCE);
            $commit = $git->readHeadCommit() ?? $pushedCommit;

            $this->forgetWorkerState();
            $this->redeploy->afterMutation($git, $project, 'push', $commit, $deployLogger);
        } catch (\Throwable $e) {
            $this->finishIfUnfinished($deployLogger, DeployLogger::STATUS_FAILED, 'Could not update the checkout to the pushed branch: ' . $e->getMessage());

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
        if ($deployLogger !== null && !$deployLogger->isFinished()) {
            $deployLogger->finish($status, $error);
        }
    }

    /**
     * Always a new deploy, never a resumed one. `latest.json` still says
     * `running` after a deploy was killed before finish(); resuming it would
     * append this push to the dead deploy's log under the dead deploy's id.
     * A deploy that is actually alive holds the lock, and start() throws on
     * that just as resume did.
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
     * A queue worker outlives its jobs, and these two hold the previous
     * deploy's `stages` and `recipe` until something resets them. A push does
     * not carry either, so it must not inherit them from whatever ran last.
     */
    protected function forgetWorkerState(): void
    {
        app(DeployPlanContext::class)->set(null);
        app(RecipeChoiceContext::class)->set(null);
    }
}
