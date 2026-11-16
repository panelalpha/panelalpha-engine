<?php

namespace App\System\Project\Dind\Source;

use App\System\Project\Dind as DindProject;

/**
 * After a mutating git call on a deploy-managed DinD checkout, rebuild the running app
 * from the files already in ~/project (does not re-clone git_repo).
 */
class CheckoutRedeploy
{
    public function afterMutation(GitRepository $git, DindProject $project): void
    {
        if (! $git->isDeployManaged()) {
            return;
        }

        $this->rebuild($project);
    }

    /** rebuildFromCheckout() records its own verdict, and a partial one is never turned into a success here. */
    protected function rebuild(DindProject $project): void
    {
        $project->deployment()->rebuildFromCheckout();
        $project->system()->webserver()->rebuildDomains();

        $user = $project->userModel();
        if (in_array($user->getDeploymentStatus(), ['success', 'partial'], true)) {
            return;
        }
        $user->markDeploySucceeded();
        if ($user->exists) {
            $user->save();
        }
    }
}
