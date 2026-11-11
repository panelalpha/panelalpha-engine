<?php

namespace Tests\Unit\Deploy;

use App\Lib\Project\PublicRouting\PublicPort;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * A port published for `public_ports:` is a game server or a broker, not the site: probing it over HTTP only warns.
 */
class HealthSkipsDeclaredPortsTest extends TestCase
{
    public function test_declared_ports_are_not_probed(): void
    {
        $declared = [new PublicPort(18081, 3000), new PublicPort(18082, 4000, PublicPort::UDP)];

        $this->assertSame([8080], AppHealth::withoutDeclaredPorts([8080, 3000, 4000], $declared, 8080));
    }

    public function test_the_routed_port_is_probed_even_when_declared(): void
    {
        $this->assertSame([3000], AppHealth::withoutDeclaredPorts([3000], [new PublicPort(18081, 3000)], 3000));
    }
}
