<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use PHPUnit\Framework\TestCase;

/**
 * engine#33: the readings the swap acts on -- where Docker published the
 * second generation, whether a container is going to answer at all, and
 * which way traffic goes back.
 */
class ZeroDowntimeRedeployTest extends TestCase
{
    public function test_the_port_docker_chose_is_read_ipv4_first(): void
    {
        $this->assertSame(32771, ZeroDowntimeRedeploy::boundPort("[::]:32772\n0.0.0.0:32771\n"));
        $this->assertSame(32772, ZeroDowntimeRedeploy::boundPort("[::]:32772\n"));
        $this->assertNull(ZeroDowntimeRedeploy::boundPort(''));
    }

    public function test_a_running_container_has_not_failed_yet(): void
    {
        $this->assertNull(ZeroDowntimeRedeploy::containerFailure('running 0 0'));
        $this->assertNull(ZeroDowntimeRedeploy::containerFailure("created 0 0\n"));
    }

    public function test_a_container_that_stopped_or_loops_will_not_answer(): void
    {
        $this->assertSame('it exited with code 1', ZeroDowntimeRedeploy::containerFailure('exited 1 0'));
        $this->assertSame('it keeps restarting (last exit code 1)', ZeroDowntimeRedeploy::containerFailure('restarting 1 3'));
        $this->assertSame('it keeps restarting (2 restarts so far)', ZeroDowntimeRedeploy::containerFailure('running 0 2'));
        $this->assertSame('its container is gone', ZeroDowntimeRedeploy::containerFailure(''));
    }

    public function test_traffic_goes_back_the_way_it_came(): void
    {
        $this->assertSame([32771 => 3000, 32772 => 9000], ZeroDowntimeRedeploy::inverse([3000 => 32771, 9000 => 32772]));
    }

    public function test_a_kept_previous_version_is_still_a_failed_start(): void
    {
        $result = ZeroDowntimeRedeploy::previousKept('', 'build failed', 0);

        $this->assertSame(1, $result['exit_code']);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
    }

    public function test_the_old_checkout_comes_back_only_while_all_its_readers_still_run(): void
    {
        $this->assertTrue(CheckoutAside::allTrue("true\ntrue\n", 2));
        $this->assertFalse(CheckoutAside::allTrue("true\ngone\n", 2), 'one was replaced');
        $this->assertFalse(CheckoutAside::allTrue("true\nfalse\n", 2));
        $this->assertFalse(CheckoutAside::allTrue("true\n", 2), 'an answer missing');
    }

    /** A redeploy pulls before it replaces anything; what it builds itself is not pulled. */
    public function test_only_registry_images_are_pulled_ahead(): void
    {
        $config = [
            'name' => 'project',
            'services' => [
                'web' => ['image' => 'traefik/whoami:v0.0.0-missing'],
                'app' => ['build' => ['context' => '.']],
                'worker' => ['image' => 'project-app:latest'],
                'api' => ['build' => ['context' => './api'], 'image' => 'acme/api:2'],
                'cli' => ['image' => 'acme/api:2'],
                'local' => ['image' => 'local-only', 'pull_policy' => 'never'],
                'db' => ['image' => 'postgres:16'],
            ],
        ];

        $this->assertSame(['web', 'db'], ZeroDowntimeRedeploy::servicesToPull($config));
        $this->assertSame([], ZeroDowntimeRedeploy::servicesToPull(['services' => []]));
    }

    /** A failed redeploy leaves the project as it was; a successful one keeps what it deployed. */
    public function test_the_old_checkout_comes_back_only_after_a_failure_that_kept_its_containers(): void
    {
        $this->assertSame('restored', CheckoutAside::outcome(false, true), 'failed, old version still serving');
        $this->assertSame('removed', CheckoutAside::outcome(false, false), 'failed after the old version was replaced');
        $this->assertSame('removed', CheckoutAside::outcome(true, true), 'succeeded without recreating anything');
        $this->assertSame('restored', CheckoutAside::outcome(null, true), 'died with the old version serving');
        $this->assertSame('removed', CheckoutAside::outcome(null, false));
    }

    /** A new version on another port: the site moves from where it is to the copy of the new port. */
    public function test_the_switch_starts_from_the_port_the_site_is_on(): void
    {
        $this->assertSame([3000 => 32771], ZeroDowntimeRedeploy::switchMap([3000 => 32771], 3000, 3000));
        $this->assertSame([8080 => 32771, 3000 => 32771], ZeroDowntimeRedeploy::switchMap([8080 => 32771], 8080, 3000));
        // Moving back after a failure lands on the port the running version answers on.
        $this->assertSame([32771 => 3000], ZeroDowntimeRedeploy::inverse(ZeroDowntimeRedeploy::switchMap([8080 => 32771], 8080, 3000)));
        // Taking traffic lands on the new version's own port.
        $this->assertSame([32771 => 8080], ZeroDowntimeRedeploy::inverse([8080 => 32771]));
    }
}
