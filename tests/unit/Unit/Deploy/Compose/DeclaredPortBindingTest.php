<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeclaredPortBinding;
use App\Lib\Project\PublicRouting\PublicPort;
use PHPUnit\Framework\TestCase;

/**
 * A declared `to` has to be published on the account container, or the rule forwards to nothing.
 */
class DeclaredPortBindingTest extends TestCase
{
    public function test_the_service_that_offers_the_port_publishes_it(): void
    {
        $compose = ['services' => [
            'web' => ['image' => 'nginx', 'ports' => ['8080:80']],
            'game' => ['image' => 'game', 'expose' => ['27015']],
        ]];

        $result = DeclaredPortBinding::apply($compose, [new PublicPort(27015, 27015, PublicPort::UDP), new PublicPort(25575, 25575)]);

        $this->assertSame(['27015:27015/udp'], $result['compose']['services']['game']['ports']);
        $this->assertSame(['8080:80', '25575:25575'], $result['compose']['services']['web']['ports'], 'offered by none: the service that publishes');
        $this->assertSame(['udp 27015 on game', 'tcp 25575 on web'], $result['published']);
    }

    public function test_a_port_already_published_is_left_alone(): void
    {
        $compose = ['services' => ['app' => ['image' => 'app', 'ports' => ['3000:3000', ['target' => 53, 'published' => '53', 'protocol' => 'udp']]]]];

        $result = DeclaredPortBinding::apply($compose, [new PublicPort(8081, 3000), new PublicPort(53, 53, PublicPort::UDP)]);

        $this->assertSame($compose, $result['compose']);
        $this->assertSame([], $result['published']);
    }

    public function test_tcp_published_is_not_udp_published(): void
    {
        $compose = ['services' => ['app' => ['image' => 'app', 'ports' => ['53:53']]]];

        $result = DeclaredPortBinding::apply($compose, [new PublicPort(53, 53, PublicPort::UDP)]);

        $this->assertSame(['53:53', '53:53/udp'], $result['compose']['services']['app']['ports']);
    }

    public function test_the_generated_app_service_takes_a_port_nobody_offers(): void
    {
        $compose = ['services' => ['db' => ['image' => 'mysql'], 'app' => ['build' => '.']]];

        $result = DeclaredPortBinding::apply($compose, [new PublicPort(9000, 9000)]);

        $this->assertSame(['9000:9000'], $result['compose']['services']['app']['ports']);
    }

    public function test_no_service_to_put_it_on_is_reported(): void
    {
        $result = DeclaredPortBinding::apply(['services' => ['worker' => ['image' => 'w']]], [new PublicPort(9000, 9000)]);

        $this->assertSame(['tcp 9000'], $result['unbound']);
        $this->assertArrayNotHasKey('ports', $result['compose']['services']['worker']);
    }
}
