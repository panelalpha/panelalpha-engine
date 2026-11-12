<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\ServerNames;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The server's own name and address go from every string of a report, not only
 * the ones the redactor reads.
 */
class ServerNamesTest extends TestCase
{
    public function test_the_servers_address_and_name_go_from_every_string_at_any_depth(): void
    {
        $address = TestNetwork::private(25);
        $mask = ServerNames::for(['panel.example.com', $address]);
        $this->assertNotNull($mask);

        $scrubbed = $mask->scrub([
            'repo' => ['host' => 'git.panel.example.com', 'path' => 'team/site'],
            'log_tail' => ["connect to {$address} port 443 failed", 'Serving under ' . str_replace('.', '-', $address) . '.panelalpha.direct'],
            'deploy' => ['port_hint' => 3000, 'manifests' => ['package.json']],
            'explained' => true,
        ]);

        $this->assertSame([
            'repo' => ['host' => 'git.<server>', 'path' => 'team/site'],
            'log_tail' => ['connect to <ip> port 443 failed', 'Serving under <ip>.panelalpha.direct'],
            'deploy' => ['port_hint' => 3000, 'manifests' => ['package.json']],
            'explained' => true,
        ], $scrubbed);
    }

    public function test_a_name_or_address_that_only_contains_the_servers_is_left_alone(): void
    {
        $address = TestNetwork::private(25);
        $mask = ServerNames::for(['panel.example.com', $address]);
        $this->assertNotNull($mask);

        $text = "mypanel.example.com and panel.example.community, {$address}5 and 1{$address}";

        $this->assertSame($text, $mask->apply($text));
    }

    public function test_a_name_that_is_no_hostname_is_not_a_pattern(): void
    {
        $this->assertNull(ServerNames::for([]));
        $this->assertNull(ServerNames::for(['', '  ', 'local', null, 42]));
    }
}
