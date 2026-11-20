<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\UnpublishedAppPort;
use PHPUnit\Framework\TestCase;

/** A service compose never starts by default, or one on another network stack, is not where the app's port is read. */
class UnpublishedAppPortSkipsTest extends TestCase
{
    public function test_profiled_and_host_network_services_are_passed_over(): void
    {
        $compose = ['services' => [
            'debugger' => ['image' => 'acme/debug', 'profiles' => ['debug'], 'environment' => ['PORT' => '4000']],
            'tools' => ['image' => 'acme/tools', 'profiles' => 'tools', 'environment' => ['PORT' => '4100']],
            'agent' => ['image' => 'acme/agent', 'network_mode' => 'host', 'environment' => ['PORT' => '4200']],
            'web' => ['image' => 'acme/web', 'environment' => ['PORT' => '3000']],
        ]];

        $found = UnpublishedAppPort::apply($compose);

        $this->assertNotNull($found);
        $this->assertSame(['web', 3000, 'its PORT variable'], [$found['service'], $found['port'], $found['source']]);
        $this->assertSame(['3000'], $found['compose']['services']['web']['expose']);
        $this->assertArrayNotHasKey('expose', $found['compose']['services']['debugger']);
        $this->assertArrayNotHasKey('expose', $found['compose']['services']['agent']);
    }

    public function test_nothing_is_found_when_only_skipped_services_name_a_port(): void
    {
        $compose = ['services' => [
            'debugger' => ['image' => 'acme/debug', 'profiles' => ['debug'], 'environment' => ['PORT' => '4000']],
            'agent' => ['image' => 'acme/agent', 'network_mode' => 'service:vpn', 'environment' => ['PORT' => '4200']],
        ]];

        $this->assertNull(UnpublishedAppPort::apply($compose));
    }
}
