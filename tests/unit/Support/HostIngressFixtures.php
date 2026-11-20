<?php

namespace Tests\Support;

trait HostIngressFixtures
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
}
