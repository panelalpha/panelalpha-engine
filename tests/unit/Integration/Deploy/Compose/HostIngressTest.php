<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\HostIngressFixtures;

/**
 * A compose file written for a host that already runs Traefik: an external
 * network compose cannot find in an account, and a proxy whose :80 port
 * detection would otherwise pick.
 */
final class HostIngressTest extends TestCase
{
    use HostIngressFixtures;

    public function test_the_web_service_port_is_chosen_once_the_proxy_is_gone(): void
    {
        $path = sys_get_temp_dir() . '/host-ingress-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($path, Yaml::dump(ComposeHarden::withoutHostIngress(self::traefikStack())['compose'], 6, 2));
        try {
            $this->assertSame(['all' => [3000], 'primary' => 3000, 'refused' => []], ComposePortScan::of($path));
        } finally {
            unlink($path);
        }
    }

    public function test_a_map_form_routing_label_becomes_expose_and_detection_finds_it(): void
    {
        $compose = ['services' => [
            'proxy' => ['image' => 'traefik:v3', 'ports' => ['80:80']],
            'web' => ['build' => '.', 'expose' => ['9000'], 'labels' => [
                'traefik.http.services.site.loadbalancer.server.port' => '8055',
            ]],
        ]];

        $result = ComposeHarden::withoutHostIngress($compose)['compose'];
        $this->assertSame(['9000', '8055'], $result['services']['web']['expose']);

        $path = sys_get_temp_dir() . '/host-ingress-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($path, Yaml::dump($result, 6, 2));
        try {
            $this->assertContains(8055, ComposePortScan::of($path)['all']);
        } finally {
            unlink($path);
        }
    }
}
