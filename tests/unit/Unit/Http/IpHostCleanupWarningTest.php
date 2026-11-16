<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\User;
use App\System;
use App\System\Network;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\HostCommand;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** Unassigning an address and deleting a subnet clean the host up best-effort; a cleanup the host refused is logged and flagged. */
class IpHostCleanupWarningTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        foreach (['defaultIpv4Route', 'defaultIpv6Route'] as $property) {
            (new \ReflectionProperty(Network::class, $property))->setValue(null, null);
        }
        Log::spy();
    }

    protected function tearDown(): void
    {
        foreach (['ip_assigned', 'ip_subnets', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_an_address_the_host_would_not_remove_is_logged_on_unassign(): void
    {
        $host = $this->hostRefusing('ip addr del', 'RTNETLINK answers: Cannot assign requested address');
        $this->app->instance(System::class, $host);
        $user = User::make(['username' => 'alice', 'domain' => 'alice.example.test']);
        $user->save();
        $subnet = IpSubnet::create(['ip' => TestNetwork::public3(0), 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
        IpAssigned::create(['user_id' => $user->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::public3(2)]);

        $this->postJson('/api/ip/unassign', ['user_id' => $user->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::public3(2)])
            ->assertOk()
            ->assertJsonPath('host_cleanup_failed', true);

        $this->assertContains(['ip', 'addr', 'del', TestNetwork::public3(2).'/29', 'dev', 'eth0'], $host->commands);
        $this->assertWarned('Could not delete IP address '.TestNetwork::public3(2).' from network interface', 'RTNETLINK answers: Cannot assign requested address');
    }

    public function test_a_route_the_host_would_not_remove_is_logged_on_subnet_delete(): void
    {
        $host = $this->hostRefusing('ip route del', 'RTNETLINK answers: No such process');
        $this->app->instance(System::class, $host);
        $subnet = IpSubnet::create(['ip' => TestNetwork::public3(0), 'mask' => 29, 'family' => 4, 'is_shared' => 0]);

        $this->deleteJson("/api/ip/subnets/{$subnet->id}")->assertOk()->assertJsonPath('host_cleanup_failed', true);

        $this->assertContains(['ip', 'route', 'del', TestNetwork::public3(0).'/29', 'via', TestNetwork::private(1), 'dev', 'eth0'], $host->commands);
        $this->assertWarned('Could not delete IP route for subnet '.TestNetwork::public3(0).'/29', 'RTNETLINK answers: No such process');
    }

    public function test_a_cleanup_the_host_took_is_not_flagged(): void
    {
        $this->app->instance(System::class, $this->hostRefusing('nothing', ''));
        $user = User::make(['username' => 'alice', 'domain' => 'alice.example.test']);
        $user->save();
        $subnet = IpSubnet::create(['ip' => TestNetwork::public3(0), 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
        IpAssigned::create(['user_id' => $user->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::public3(2)]);

        $this->postJson('/api/ip/unassign', ['user_id' => $user->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::public3(2)])
            ->assertOk()
            ->assertJsonPath('host_cleanup_failed', false)
            ->assertJsonPath('reload_pending', false);
        $this->deleteJson("/api/ip/subnets/{$subnet->id}")->assertOk()->assertJsonPath('host_cleanup_failed', false);
    }

    private function assertWarned(string $message, string $errorOutput): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $context = []): bool => $m === $message && ($context['error_output'] ?? null) === $errorOutput)
            ->once();
    }

    private function hostRefusing(string $refused, string $error): System
    {
        return new class ($refused, $error) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $refused, private string $error) {}

            public function rebuildDomains(): bool
            {
                return true;
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = HostCommand::argv($cmd);
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;
                $joined = implode(' ', $argv);

                return match (true) {
                    str_contains($joined, 'route show default') => FakeProcess::ok('default via '.TestNetwork::private(1).' dev eth0'),
                    str_starts_with($joined, $this->refused) => FakeProcess::failed($this->error, 2),
                    default => FakeProcess::ok(),
                };
            }
        };
    }
}
