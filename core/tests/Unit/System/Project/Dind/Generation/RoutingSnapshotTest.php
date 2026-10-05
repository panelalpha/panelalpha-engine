<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use Tests\TestCase;

/**
 * A redeploy that changes the app's port must not move the site before the
 * new version takes traffic, and a failed one puts the details back.
 */
class RoutingSnapshotTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'route-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_only_the_details_a_later_start_reads_are_kept(): void
    {
        $picked = RoutingSnapshot::pick([
            'app_port' => 3000,
            'deploy_strategy' => 'dockerfile',
            'deploy_image' => null,
            'deployment_status' => 'success',
            'error' => null,
        ]);

        $this->assertSame(3000, $picked['app_port']);
        $this->assertSame('dockerfile', $picked['deploy_strategy']);
        $this->assertArrayHasKey('deploy_runtime', $picked, 'absent before: put back as absent');
        $this->assertNull($picked['deploy_runtime']);
        $this->assertArrayNotHasKey('deployment_status', $picked, 'the failure records its own status');
    }

    public function test_the_rules_wait_while_this_process_redeploys(): void
    {
        $this->assertFalse(RoutingSnapshot::defers($this->username));
        $this->assertNull(RoutingSnapshot::servedPort($this->username));

        $state = new GenerationState($this->username);
        $state->put(GenerationState::ROUTES, ['details' => ['app_port' => 3000], 'applied' => false, 'owner' => GenerationState::owner()]);
        $this->assertTrue(RoutingSnapshot::defers($this->username));
        $this->assertSame(3000, RoutingSnapshot::servedPort($this->username));

        $state->put(GenerationState::ROUTES, ['details' => ['app_port' => 3000], 'applied' => true, 'owner' => GenerationState::owner()]);
        $this->assertFalse(RoutingSnapshot::defers($this->username), 'the new version took traffic');

        $other = ['pid' => GenerationState::owner()['pid'] + 1, 'start' => null, 'at' => time()];
        $state->put(GenerationState::ROUTES, ['details' => ['app_port' => 3000], 'applied' => false, 'owner' => $other]);
        $this->assertFalse(RoutingSnapshot::defers($this->username), 'another process: a sweep or a later request');
    }
}
