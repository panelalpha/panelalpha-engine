<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Tests\TestCase;

/**
 * What RoutingSnapshot::settle() and routeBack() leave behind for each verdict
 * and container state, without proxy rules in the way.
 */
class RoutingSnapshotSettleTest extends TestCase
{
    private string $username = '';

    /** @var list<int> */
    private array $routed = [];

    /** @var list<array<string, mixed>> */
    private array $detailsSet = [];

    private int $saves = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'settle-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_nothing_taken_settles_nothing(): void
    {
        $this->assertNull((new RoutingSnapshot($this->project([true])))->settle(false));
        $this->assertSame([], $this->routed);
    }

    public function test_a_second_copy_holding_the_site_keeps_its_routes(): void
    {
        $this->taken(['c1']);
        (new GenerationState($this->username))->put(GenerationState::NEXT, ['rules' => [7], 'routes' => [32771]]);

        $this->assertSame('routes left on the second copy', (new RoutingSnapshot($this->project([true])))->settle(false));
        $this->assertNull($this->state()->get(GenerationState::ROUTES));
        $this->assertSame([], $this->routed);
        $this->assertSame([], $this->detailsSet);
    }

    public function test_a_success_routes_the_new_version(): void
    {
        $this->taken(['c1']);

        $this->assertNull((new RoutingSnapshot($this->project([true])))->settle(true));
        $this->assertSame([8080], $this->routed);
        $this->assertNull($this->state()->get(GenerationState::ROUTES));
        $this->assertSame([], $this->detailsSet);
    }

    public function test_a_failure_with_the_old_containers_running_restores_the_details(): void
    {
        $this->taken(['c1', 'c2']);

        $this->assertSame('deploy details restored', (new RoutingSnapshot($this->project([true, true])))->settle(false));
        $this->assertSame([['deploy_strategy' => 'dockerfile']], $this->detailsSet);
        $this->assertSame(1, $this->saves);
        $this->assertSame([], $this->routed);
        $this->assertNull($this->state()->get(GenerationState::ROUTES));
    }

    public function test_an_unknown_verdict_with_a_container_gone_routes_the_new_version(): void
    {
        $this->taken(['c1', 'c2']);

        $this->assertNull((new RoutingSnapshot($this->project([true, false])))->settle(null));
        $this->assertSame([8080], $this->routed);
        $this->assertSame([], $this->detailsSet);
    }

    public function test_a_failure_with_no_containers_recorded_routes_the_new_version(): void
    {
        $this->taken([]);

        $this->assertNull((new RoutingSnapshot($this->project([true])))->settle(false));
        $this->assertSame([8080], $this->routed);
    }

    public function test_containers_that_cannot_be_read_leave_the_snapshot_for_later(): void
    {
        $this->taken(['c1']);

        $this->assertNull((new RoutingSnapshot($this->project(null)))->settle(false));
        $this->assertNotNull($this->state()->get(GenerationState::ROUTES));
        $this->assertSame([], $this->routed);
    }

    public function test_a_routing_that_fails_leaves_the_snapshot_for_later(): void
    {
        $this->taken(['c1']);

        $this->assertNull((new RoutingSnapshot($this->project([true], routingFails: true)))->settle(true));
        $this->assertTrue($this->state()->get(GenerationState::ROUTES)['applied']);
    }

    public function test_a_route_back_that_fails_still_forgets_the_snapshot(): void
    {
        $this->state()->put(GenerationState::ROUTES, [
            'details' => ['app_port' => 3000], 'containers' => ['c1'], 'applied' => true, 'owner' => GenerationState::owner(),
        ]);

        (new RoutingSnapshot($this->project([true], routingFails: true)))->routeBack();

        $this->assertNull($this->state()->get(GenerationState::ROUTES));
    }

    public function test_a_route_back_with_nothing_taken_does_nothing(): void
    {
        (new RoutingSnapshot($this->project([true])))->routeBack();

        $this->assertSame([], $this->routed);
    }

    /** @param list<string> $containers */
    private function taken(array $containers): void
    {
        $this->state()->put(GenerationState::ROUTES, [
            'details' => ['deploy_strategy' => 'dockerfile'],
            'containers' => $containers,
            'applied' => false,
            'owner' => GenerationState::owner(),
        ]);
    }

    private function state(): GenerationState
    {
        return new GenerationState($this->username);
    }

    /** @param ?list<bool> $running whether each container the inspect answers for runs; null when it cannot run */
    private function project(?array $running, bool $routingFails = false): Dind
    {
        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturn(8080);
        $user->method('setDetails')->willReturnCallback(function (array $details) use ($user): User {
            $this->detailsSet[] = $details;

            return $user;
        });
        $user->method('save')->willReturnCallback(function (): bool {
            $this->saves++;

            return true;
        });
        $networking = $this->createStub(Networking::class);
        $networking->method('applyRoutes')->willReturnCallback(function (User $u, int $port) use ($routingFails): void {
            if ($routingFails) {
                throw new \RuntimeException('webserver reload failed');
            }
            $this->routed[] = $port;
        });
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($this->createStub(Webserver::class));
        $system->method('exec')->willReturnCallback(function () use ($running): string {
            if ($running === null) {
                throw new \RuntimeException('Cannot connect to the Docker daemon');
            }

            return (string) json_encode(array_map(static fn (bool $runs): array => ['State' => ['Running' => $runs]], $running));
        });
        $project = $this->createStub(Dind::class);
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('username')->willReturn($this->username);
        $project->method('userModel')->willReturn($user);
        $project->method('system')->willReturn($system);
        $project->method('networking')->willReturn($networking);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($project));

        return $project;
    }
}
