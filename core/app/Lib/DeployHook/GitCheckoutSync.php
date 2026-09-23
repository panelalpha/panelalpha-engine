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

        // The deploy -- and with it the account's lock -- starts before the
        // forced pull, not after it: `reset --hard` and `clean -fd` rewrite
        // the very files a running deploy is building from. A deploy that
        // started after this job was queued makes this throw
        // DeployAlreadyRunningException here, with nothing touched yet.
        $deployLogger = $this->startDeploy($user);
        if ($deployLogger !== null && $onDeployStarted !== null) {
            $onDeployStarted($deployLogger->getDeployId());
        }

        try {
            $git->pull(ProjectGit::STRATEGY_FORCE);

            // What the checkout holds now, rather than what the push said it
            // would: the two differ when another push landed in between.
            $commit = $git->readHeadCommit() ?? $pushedCommit;

            $this->forgetWorkerState();
            $this->redeploy->afterMutation($git, $project, 'push', $commit, $deployLogger);
        } catch (\Throwable $e) {
            // The rebuild finishes its own deploy, whatever its outcome; a
            // pull that failed never reached it, and must not leave the
            // deploy it started `running`.
            if ($deployLogger?->isRunning()) {
                $deployLogger->finish(DeployLogger::STATUS_FAILED, 'Could not update the checkout to the pushed branch: ' . $e->getMessage());
            }

            throw $e;
        }

        if ($deployLogger?->isRunning()) {
            // A runtime with nothing to rebuild returned without finishing it.
            $deployLogger->finish(DeployLogger::STATUS_SUCCESS);
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
