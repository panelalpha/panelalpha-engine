<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Network;
use Symfony\Component\Process\Process;
use Tests\Support\TestNetwork;
use Tests\TestCase;

class NetworkIpv4NatMapsTest extends TestCase
{
    public function test_default_lookup_url_is_set(): void
    {
        $this->assertSame('https://icanhazip.com', Network::DEFAULT_IPV4_NAT_LOOKUP_URL);
    }

    public function test_local_ipv4_addresses_excludes_loopback_and_unspecified(): void
    {
        $loopback = TestNetwork::LOOPBACK;
        $address = TestNetwork::private(5);
        $broadcast = TestNetwork::private(255);
        $thisNetwork = TestNetwork::THIS_NETWORK;
        $output = <<<TXT
1: lo: <LOOPBACK,UP>
    inet {$loopback}/8 scope host lo
2: eth0: <BROADCAST,UP>
    inet {$address}/24 brd {$broadcast} scope global eth0
3: dummy0:
    inet {$thisNetwork}/32 scope global dummy0
TXT;

        $process = $this->createStub(Process::class);
        $process->method('getOutput')->willReturn($output);

        $system = new class ($process) extends System {
            public function __construct(private Process $process)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->process;
            }
        };

        $ips = (new Network($system))->localIpv4Addresses();

        $this->assertSame([$address], $ips);
    }
}
