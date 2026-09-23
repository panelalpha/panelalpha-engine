<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A compose file written for a host that already runs Traefik: an external
 * network compose cannot find in an account, and a proxy whose :80 port
 * detection would otherwise pick.
 */
final class HostIngressTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function traefikStack(): array
    {
        return [
            'services' => [
                'traefik' => [
                    'image' => 'traefik:v3.1',
                    'ports' => ['80:80', '443:443'],
                    'networks' => ['traefik_proxy'],
                ],
                'web' => [
                    'build' => '.',
                    'ports' => ['3000:3000'],
                    'depends_on' => ['traefik', 'db'],
                    'labels' => ['traefik.enable=true'],
                    'networks' => ['traefik_proxy', 'backend'],
                ],
                'worker' => [
                    'build' => '.',
                    'depends_on' => ['traefik' => ['condition' => 'service_started']],
                    'networks' => ['traefik_proxy' => null, 'backend' => ['aliases' => ['jobs']]],
                ],
                'db' => [
                    'image' => 'postgres:16',
                    'networks' => ['backend'],
                ],
                'edge' => [
                    'image' => 'acme/edge',
                    'networks' => ['legacy_proxy'],
                ],
            ],
            'networks' => [
                'traefik_proxy' => ['external' => true],
                'legacy_proxy' => ['external' => ['name' => 'proxy']],
                'backend' => ['driver' => 'bridge'],
            ],
        ];
    }

    public function test_external_networks_are_removed_from_the_top_level(): void
    {
        $compose = ComposeHarden::withoutHostIngress(self::traefikStack())['compose'];

        $this->assertSame(['backend' => ['driver' => 'bridge']], $compose['networks']);
    }

    public function test_external_networks_are_removed_from_list_and_map_forms(): void
    {
        $services = ComposeHarden::withoutHostIngress(self::traefikStack())['compose']['services'];

        $this->assertSame(['backend'], $services['web']['networks']);
        $this->assertSame(['backend' => ['aliases' => ['jobs']]], $services['worker']['networks']);
        // Nothing left: the key goes, so it joins the default network.
        $this->assertArrayNotHasKey('networks', $services['edge']);
    }

    public function test_a_file_with_no_external_network_is_untouched(): void
    {
        $compose = [
            'services' => ['web' => ['image' => 'acme/web', 'networks' => ['backend']]],
            'networks' => ['backend' => ['external' => false]],
        ];

        $result = ComposeHarden::withoutHostIngress($compose);

        $this->assertSame($compose, $result['compose']);
        $this->assertSame([], $result['dropped']);
    }

    public function test_the_proxy_service_and_references_to_it_are_dropped(): void
    {
        $result = ComposeHarden::withoutHostIngress(self::traefikStack());
        $services = $result['compose']['services'];

        $this->assertArrayNotHasKey('traefik', $services);
        $this->assertSame(['db'], $services['web']['depends_on']);
        $this->assertArrayNotHasKey('depends_on', $services['worker']);
        $this->assertCount(3, $result['dropped']);
    }

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

    public function test_a_list_form_routing_label_becomes_expose(): void
    {
        $compose = ['services' => [
            'traefik' => ['image' => 'traefik:v3', 'ports' => ['80:80']],
            'web' => ['build' => '.', 'labels' => [
                'traefik.enable=true',
                'traefik.http.services.web.loadbalancer.server.port=3000',
                'traefik.http.services.alt.loadbalancer.server.port=${PORT}',
            ]],
        ]];

        $web = ComposeHarden::withoutHostIngress($compose)['compose']['services']['web'];

        $this->assertSame(['3000'], $web['expose']);
        $this->assertContains('traefik.enable=true', $web['labels']);
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

    public function test_a_routed_port_already_published_is_not_duplicated(): void
    {
        $compose = ['services' => [
            'traefik' => ['image' => 'traefik:v3'],
            'web' => ['build' => '.', 'ports' => ['8080:3000'], 'labels' => [
                'traefik.http.services.web.loadbalancer.server.port=3000',
            ]],
        ]];

        $this->assertArrayNotHasKey('expose', ComposeHarden::withoutHostIngress($compose)['compose']['services']['web']);
    }

    public function test_routing_labels_are_left_alone_when_no_proxy_is_dropped(): void
    {
        $compose = ['services' => [
            'web' => ['build' => '.', 'labels' => ['traefik.http.services.web.loadbalancer.server.port=3000']],
        ]];

        $this->assertSame($compose, ComposeHarden::withoutHostIngress($compose)['compose']);
    }

    public function test_other_proxy_images_are_recognised(): void
    {
        foreach ([
            'jwilder/nginx-proxy:alpine',
            'nginxproxy/nginx-proxy',
            'lucaslorentz/caddy-docker-proxy:ci-alpine',
            'docker.io/library/traefik:v2.11@sha256:abc',
        ] as $image) {
            $compose = ['services' => ['proxy' => ['image' => $image], 'web' => ['image' => 'acme/web']]];

            $this->assertArrayNotHasKey('proxy', ComposeHarden::withoutHostIngress($compose)['compose']['services'], $image);
        }
    }

    public function test_an_ordinary_nginx_or_a_lone_proxy_is_kept(): void
    {
        $compose = ['services' => [
            'nginx' => ['image' => 'nginx:alpine'],
            'proxy' => ['image' => 'acme/nginx-proxy'],
            'whoami' => ['image' => 'traefik/whoami'],
        ]];
        $this->assertSame($compose, ComposeHarden::withoutHostIngress($compose)['compose']);

        $alone = ['services' => ['traefik' => ['image' => 'traefik:v3']]];
        $this->assertSame($alone, ComposeHarden::withoutHostIngress($alone)['compose']);
    }
}
