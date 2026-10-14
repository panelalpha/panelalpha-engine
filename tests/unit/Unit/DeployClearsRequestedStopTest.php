<?php

namespace Tests\Unit;

use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use Tests\TestCase;

/**
 * A stop asked for holds until a deploy has finished with the app running: a
 * cancelled or failed redeploy of a stopped app leaves it stopped on request.
 */
class DeployClearsRequestedStopTest extends TestCase
{
    private function stoppedUser(array $details): User
    {
        $user = new class extends User {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $user->domain = 'shop.example.com';
        $user->setDetails($details + ['app_stopped_by_request' => true]);

        return $user;
    }

    public function test_a_deploy_that_succeeded_ends_the_requested_stop(): void
    {
        $user = $this->stoppedUser(['deployment_status' => 'failed']);

        $user->markDeploySucceeded();

        $this->assertFalse($user->isAppStoppedByRequest());
    }

    public function test_a_rebuild_on_an_already_clean_project_still_ends_the_requested_stop(): void
    {
        $user = $this->stoppedUser(['deployment_status' => 'success', 'deployment_warnings' => []]);

        ProjectRebuild::recordSucceeded($user);

        $this->assertFalse($user->isAppStoppedByRequest());
        $this->assertSame('success', $user->getDeploymentStatus());
    }
}
