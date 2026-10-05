<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Networking;
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
