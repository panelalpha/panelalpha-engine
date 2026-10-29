<?php

namespace Tests\Unit\System;

use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\System;
use App\System\Network;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `rebuildIpv4NatMaps(replaceDefaultIpv4: true)` with default_ipv4 naming a local or a public address. */
class NetworkDefaultIpv4ReplaceTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
    }

    public function test_a_local_default_moves_to_its_public_address(): void
    {
        Setting::set('default_ipv4', TestNetwork::private(5));

        $result = $this->rebuildWithPublicIp(TestNetwork::public(7));

        $this->assertTrue($result['default_ipv4_replaced']);
        $this->assertSame(TestNetwork::public(7), Setting::get('default_ipv4'));
    }

    public function test_a_default_on_the_old_public_address_follows_it_to_the_new_one(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(5), TestNetwork::public(7));
        Setting::set('default_ipv4', TestNetwork::public(7));

        $result = $this->rebuildWithPublicIp(TestNetwork::public2(9));

        $this->assertTrue($result['default_ipv4_replaced']);
        $this->assertSame(TestNetwork::public(7), $result['default_ipv4_previous']);
        $this->assertSame(TestNetwork::public2(9), Setting::get('default_ipv4'));
    }

    public function test_a_default_already_on_the_current_public_address_is_not_replaced(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(5), TestNetwork::public(7));
        Setting::set('default_ipv4', TestNetwork::public(7));

        $result = $this->rebuildWithPublicIp(TestNetwork::public(7));

        $this->assertFalse($result['default_ipv4_replaced']);
        $this->assertSame(TestNetwork::public(7), Setting::get('default_ipv4'));
    }

    public function test_an_unrelated_default_is_left_alone(): void
    {
        Setting::set('default_ipv4', TestNetwork::public3(44));

        $result = $this->rebuildWithPublicIp(TestNetwork::public(7));

        $this->assertFalse($result['default_ipv4_replaced']);
        $this->assertSame(TestNetwork::public3(44), Setting::get('default_ipv4'));
    }

    /** @return array{maps: array<Ipv4NatMap>, default_ipv4_replaced: bool, default_ipv4_previous: ?string, default_ipv4_current: ?string} */
    private function rebuildWithPublicIp(string $publicIp): array
    {
        Http::fake(['lookup.test/*' => Http::response($publicIp . "\n")]);

        return (new Network($this->hostWithLocalIp()))->rebuildIpv4NatMaps('https://lookup.test/ip', true);
    }

    private function hostWithLocalIp(): System
    {
        $process = $this->createStub(Process::class);
        $process->method('getOutput')->willReturn("2: eth0:\n    inet " . TestNetwork::private(5) . "/24 scope global eth0\n");

        return new class ($process) extends System {
            public function __construct(private Process $process)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->process;
            }
        };
    }
}
