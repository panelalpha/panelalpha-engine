<?php

namespace App\System\Project\Git;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Git as ProjectGit;

/**
 * After a mutating git porcelain call on a deploy-managed checkout, rebuild
 * the running app from the files already in ~/project.
 *
 * Must not live in {@see ProjectGit}: that class talks to git, not to DinD.
 * Must not call a wipe-and-reclone rebuild: that path would undo pull/revert.
 */
class CheckoutRedeploy
{
    /**
     * @param string  $source what the deploy log says started it (`git` for a manual porcelain call, `push` for a Deploy Hook)
     * @param ?string $commit the commit the checkout is at, named in the log when known
     * @param ?DeployLogger $deployLogger a deploy the caller already started -- and holds the lock
     *                                    of -- before mutating the checkout; null starts one here
     */
    public function afterMutation(ProjectGit $git, ProjectAggregate $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        if (!$git->isDeployManaged()) {
            return;
        }

        $this->rebuild($project, $source, $commit, $deployLogger);
    }

    protected function rebuild(ProjectAggregate $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        $runtime = $project->runtime();
        if (!$runtime instanceof Dind) {
            return;
        }

        $runtime->deployment()->rebuildFromCheckout($deployLogger, $source, $commit);
        $project->system()->webserver()->rebuildDomains();

        $user = $project->model();
        // rebuildFromCheckout() already recorded its own verdict; a partial one must not be
        // turned into a success here.
        if (in_array($user->getDeploymentStatus(), ['success', 'partial'], true)) {
            return;
        }
        $user->markDeploySucceeded();
        if ($user->exists) {
            $user->save();
        }
    }
}
