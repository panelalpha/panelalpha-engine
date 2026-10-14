<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Network;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NetworkIpv4NatMapsTest extends TestCase
{
    public function test_default_lookup_url_is_set(): void
    {
        $this->assertSame('https://icanhazip.com', Network::DEFAULT_IPV4_NAT_LOOKUP_URL);
    }

    public function test_local_ipv4_addresses_excludes_loopback_and_unspecified(): void
    {
        $output = <<<'TXT'
1: lo: <LOOPBACK,UP>
    inet 127.0.0.1/8 scope host lo
2: eth0: <BROADCAST,UP>
    inet 10.0.0.5/24 brd 10.0.0.255 scope global eth0
3: dummy0:
    inet 0.0.0.1/32 scope global dummy0
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

        $this->assertSame(['10.0.0.5'], $ips);
    }
}
