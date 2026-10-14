<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\User;
use App\System;
use App\System\Network;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `POST /ip/assign` used to store the assignment, try to add the address to
 * the host interface, and answer 200 whatever happened -- the only trace of a
 * failure was a log warning. The caller believed the dedicated IP was live
 * while the sites had been rebuilt to listen on an address the host lacks.
 */
class IpAssignVerificationTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        self::forgetCachedDefaultRoutes();
    }

    protected function tearDown(): void
    {
        foreach (['ip_assigned', 'ip_subnets', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_host_step_that_does_not_take_is_refused_and_rolled_back(): void
    {
        $user = $this->user('alice');
        $subnet = IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 0]);

        // The interface never gets the address, though the command reports success.
        $this->app->instance(System::class, $this->fakeSystem(interface: [], noDefaultRoute: false));

        $response = $this->postJson('/api/ip/assign', [
            'user_id' => $user->id,
            'ip_subnet_id' => $subnet->id,
            'ip_address' => '192.0.2.2',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('does not carry 192.0.2.2/29', (string) $response->json('errors.ip_address.0'));
        $this->assertSame(0, IpAssigned::query()->count(), 'the assignment is rolled back');
    }

    public function test_a_host_step_that_throws_is_refused_and_rolled_back(): void
    {
        $user = $this->user('alice');
        $subnet = IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 0]);

        // No default route: the command builder itself throws, as a host with
        // no route to the subnet would.
        $this->app->instance(System::class, $this->fakeSystem(interface: [], noDefaultRoute: true));

        $response = $this->postJson('/api/ip/assign', [
            'user_id' => $user->id,
            'ip_subnet_id' => $subnet->id,
            'ip_address' => '192.0.2.2',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Could not add IP address', (string) $response->json('errors.ip_address.0'));
        $this->assertSame(0, IpAssigned::query()->count());
    }

    public function test_an_assignment_the_host_actually_took_is_reported(): void
    {
        $user = $this->user('alice');
        $subnet = IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 0]);

        $this->app->instance(System::class, $this->fakeSystem(interface: ['192.0.2.2/29'], noDefaultRoute: false));

        $response = $this->postJson('/api/ip/assign', [
            'user_id' => $user->id,
            'ip_subnet_id' => $subnet->id,
            'ip_address' => '192.0.2.2',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ip_address', '192.0.2.2');
        $this->assertSame(1, IpAssigned::query()->count());
    }

    public function test_an_ipv6_address_in_a_non_canonical_spelling_is_assigned_as_the_kernel_prints_it(): void
    {
        $user = $this->user('alice');
        $subnet = IpSubnet::create(['ip' => '2001:db8::', 'mask' => 64, 'family' => 6, 'is_shared' => 0]);

        $this->app->instance(System::class, $this->fakeSystem(interface: ['2001:db8::5/64'], noDefaultRoute: false));

        $response = $this->postJson('/api/ip/assign', [
            'user_id' => $user->id,
            'ip_subnet_id' => $subnet->id,
            'ip_address' => '2001:DB8:0:0::5',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ip_address', '2001:db8::5');
        $this->assertSame('2001:db8::5', IpAssigned::query()->value('ip_address'));
    }

    public function test_an_address_the_check_does_not_find_is_taken_off_the_interface(): void
    {
        $user = $this->user('alice');
        $subnet = IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
        $system = $this->fakeSystem(interface: [], noDefaultRoute: false);
        $this->app->instance(System::class, $system);

        $this->postJson('/api/ip/assign', [
            'user_id' => $user->id,
            'ip_subnet_id' => $subnet->id,
            'ip_address' => '192.0.2.2',
        ])->assertStatus(422);

        $removed = array_filter($system->commands, static fn (array $argv): bool => array_slice($argv, 0, 4) === ['ip', 'addr', 'del', '192.0.2.2/29']);
        $this->assertCount(1, $removed, 'the address is taken off the interface');
    }

    public function test_addresses_compare_by_value_not_spelling(): void
    {
        $this->assertTrue(Network::sameAddress('2001:db8:0:0::5', '2001:db8::5'));
        $this->assertTrue(Network::sameAddress('2001:DB8::5', '2001:db8::5'));
        $this->assertFalse(Network::sameAddress('2001:db8::5', '2001:db8::6'));
        $this->assertTrue(Network::sameAddress('192.0.2.2', '192.0.2.2'));
    }

    /** Network caches the host's default route statically; drop it between tests. */
    private static function forgetCachedDefaultRoutes(): void
    {
        foreach (['defaultIpv4Route', 'defaultIpv6Route'] as $property) {
            $ref = new \ReflectionProperty(\App\System\Network::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    private function user(string $username): User
    {
        /** @var User $user */
        $user = User::make(['username' => $username, 'domain' => $username . '.example.test']);
        $user->save();

        return $user;
    }

    /**
     * @param list<string> $interface addresses the host interface answers with
     */
    private function fakeSystem(array $interface, bool $noDefaultRoute): System
    {
        return new class ($interface, $noDefaultRoute) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(
                private array $interface,
                private bool $noDefaultRoute,
            ) {
            }

            public function rebuildDomains(): bool
            {
                return true;
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->commands[] = $argv;
                $joined = implode(' ', $argv);

                if (str_contains($joined, 'route show default')) {
                    return $this->noDefaultRoute
                        ? FakeProcess::failed('No default route found for IPv4')
                        : FakeProcess::ok("default via 10.0.0.1 dev eth0\n");
                }

                if (str_contains($joined, 'addr show')) {
                    return FakeProcess::ok(
                        "2: eth0: <UP>\n    inet 10.0.0.5/24 scope global eth0\n"
                        . implode('', array_map(fn (string $cidr): string => '    inet' . (str_contains($cidr, ':') ? '6' : '') . " {$cidr} scope global eth0\n", $this->interface))
                    );
                }

                return FakeProcess::ok();
            }
        };
    }
}
