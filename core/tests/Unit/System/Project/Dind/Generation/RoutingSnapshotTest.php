<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\RouteSwitch;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A redeploy that changes the app's port must not move the site before the
 * new version takes traffic, and a failed one puts the details back.
 */
class RoutingSnapshotTest extends TestCase
{
    private string $username = '';

    private bool $withRules = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'route-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        if ($this->withRules) {
            Schema::dropIfExists('proxy_rules');
        }
        parent::tearDown();
    }

    /** engine#692: a sweep after a dead redeploy keeps a new version only if it passed its health check. */
    public function test_only_the_redeploying_process_marks_its_new_version_healthy(): void
    {
        $state = new GenerationState($this->username);
        $other = ['pid' => GenerationState::owner()['pid'] + 1, 'start' => null, 'at' => time()];
        $state->put(GenerationState::ROUTES, ['details' => ['app_port' => 3000], 'gated' => false, 'owner' => $other]);
        RoutingSnapshot::markGated($this->username);
        $this->assertFalse($state->get(GenerationState::ROUTES)['gated']);

        $state->put(GenerationState::ROUTES, ['details' => ['app_port' => 3000], 'gated' => false, 'owner' => GenerationState::owner()]);
        RoutingSnapshot::markGated($this->username);
        $this->assertTrue($state->get(GenerationState::ROUTES)['gated']);
    }

    /** engine#691: the operator's rule to the old port goes where the new version answers. */
    public function test_taking_traffic_on_a_new_port_moves_the_operators_rules_with_the_site(): void
    {
        $this->proxyRules();
        $hand = (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true, 'transport' => 'tcp',
            'listen_port' => 25691, 'upstream_host' => $this->username, 'upstream_port' => 3000, 'is_generated' => false,
        ])->id;
        (new GenerationState($this->username))->put(GenerationState::ROUTES, [
            'details' => ['app_port' => 3000], 'containers' => ['c1'], 'applied' => false, 'owner' => GenerationState::owner(),
        ]);
        $routed = [];
        $project = $this->project(8080, $routed);

        (new RoutingSnapshot($project))->apply();

        $this->assertSame(8080, ProxyRule::find($hand)->upstream_port);
        $this->assertSame([8080], $routed, 'the engine\'s own rules');
        $this->assertTrue((new GenerationState($this->username))->get(GenerationState::ROUTES)['applied']);
    }

    /**
     * A port change 3000 -> 8080 through the second copy, then a restore of
     * the previous version: every rule of the operator's that the redeploy
     * moved goes back, whichever way it moved; one its owner changed or
     * removed meanwhile, or added, is left as they made it.
     */
    public function test_a_restore_puts_back_every_operator_rule_the_redeploy_moved(): void
    {
        $this->proxyRules();
        $site = $this->handRule('http', 443, 3000, generated: true);
        $http = $this->handRule('http', 18691, 3000);
        $tcp = $this->handRule('tcp', 25691, 3000);
        $off = $this->handRule('http', 18693, 3000, enabled: false);
        $admin = $this->handRule('http', 18692, 9000);
        $edited = $this->handRule('http', 18694, 3000);
        $removed = $this->handRule('http', 18695, 3000);
        $appPort = 3000;
        $routed = [];
        $project = $this->runningProject($appPort, $routed);
        $snapshot = new RoutingSnapshot($project);

        $snapshot->take();
        // The switch: to the second copy, then on to the new version's port.
        $switch = new RouteSwitch($project->system(), $this->username);
        $moved = $switch->move([3000 => 32771]);
        $switch->move([32771 => 8080], $moved);
        $appPort = 8080;
        $snapshot->apply();
        // Meanwhile the owner points one rule elsewhere, removes one and adds one.
        ProxyRule::query()->whereKey($edited)->update(['upstream_port' => 9001]);
        ProxyRule::query()->whereKey($removed)->delete();
        $added = $this->handRule('http', 18696, 8080);
        $this->assertSame(8080, ProxyRule::find($http)->upstream_port);
        $this->assertSame(8080, ProxyRule::find($off)->upstream_port);

        $this->assertSame(3000, $snapshot->restoreDetails());
        $snapshot->routeBack();

        foreach ([$http, $tcp, $off] as $id) {
            $this->assertSame(3000, ProxyRule::find($id)->upstream_port, "rule {$id}");
        }
        $this->assertFalse(ProxyRule::find($off)->enabled, 'switched off by its owner, and stays so');
        $this->assertSame(9000, ProxyRule::find($admin)->upstream_port, 'another port of the app');
        $this->assertSame(9001, ProxyRule::find($edited)->upstream_port, 'the owner\'s edit stands');
        $this->assertNull(ProxyRule::find($removed));
        $this->assertSame(8080, ProxyRule::find($added)->upstream_port, 'not one the redeploy moved');
        $this->assertSame(8080, ProxyRule::find($site)->upstream_port, 'the engine\'s own pair is applyRoutes\' to move');
        $this->assertSame([8080, 3000], $routed);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::ROUTES));
    }

    /** The same port before and after: a restore has nothing to move. */
    public function test_a_same_port_restore_moves_no_rule(): void
    {
        $this->proxyRules();
        $http = $this->handRule('http', 18691, 3000);
        $appPort = 3000;
        $routed = [];
        $project = $this->runningProject($appPort, $routed);
        $snapshot = new RoutingSnapshot($project);
        $snapshot->take();
        $before = ProxyRule::find($http)->updated_at;

        $snapshot->restoreDetails();
        $snapshot->routeBack();

        $this->assertSame(3000, ProxyRule::find($http)->upstream_port);
        $this->assertEquals($before, ProxyRule::find($http)->updated_at, 'not saved again');
        $this->assertSame([3000], $routed);
    }

    private function handRule(string $transport, int $listen, int $upstream, bool $enabled = true, bool $generated = false): int
    {
        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => $enabled, 'transport' => $transport,
            'listen_port' => $listen, 'upstream_host' => $this->username, 'upstream_port' => $upstream, 'is_generated' => $generated,
        ])->id;
    }

    /**
     * An account whose app runs one container, old1; its port is $appPort.
     *
     * @param list<int> $routed
     */
    private function runningProject(int &$appPort, array &$routed): Dind
    {
        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturnCallback(function () use (&$appPort): int {
            return $appPort;
        });
        $user->method('getDetails')->willReturnCallback(function () use (&$appPort): array {
            return ['app_port' => $appPort];
        });
        $networking = $this->createStub(Networking::class);
        $networking->method('applyRoutes')->willReturnCallback(function (User $u, int $port) use (&$routed): void {
            $routed[] = $port;
        });
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($this->createStub(Webserver::class));
        // `docker compose ps` + inspect, as ProjectBindMounts reads it: one running container.
        $system->method('exec')->willReturn("old1\t/app\n");
        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('userModel')->willReturn($user);
        $project->method('system')->willReturn($system);
        $project->method('networking')->willReturn($networking);
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }

    /** @param list<int> $routed */
    private function project(int $appPort, array &$routed): Dind
    {
        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturn($appPort);
        $networking = $this->createStub(Networking::class);
        $networking->method('applyRoutes')->willReturnCallback(function (User $u, int $port) use (&$routed): void {
            $routed[] = $port;
        });
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($this->createStub(Webserver::class));
        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('userModel')->willReturn($user);
        $project->method('system')->willReturn($system);
        $project->method('networking')->willReturn($networking);

        return $project;
    }

    private function proxyRules(): void
    {
        $this->withRules = true;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('proxy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('owner_scope')->default('user');
            $table->string('username')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('transport');
            $table->string('listen_ip')->nullable()->default('*');
            $table->unsignedInteger('listen_port');
            $table->string('server_name')->nullable();
            $table->string('upstream_host');
            $table->unsignedInteger('upstream_port');
            $table->string('upstream_protocol')->nullable();
            $table->boolean('is_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
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
