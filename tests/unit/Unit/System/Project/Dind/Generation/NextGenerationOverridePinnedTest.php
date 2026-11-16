<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\System\Project\Dind\Generation\NextGeneration;
use PHPUnit\Framework\TestCase;

/**
 * The second generation's override and refusals, pinned as text for the shapes the
 * other tests leave out: a built service without an image, no networks, named volumes.
 */
class NextGenerationOverridePinnedTest extends TestCase
{
    public function test_a_built_service_without_an_image_is_named_after_the_project(): void
    {
        $config = [
            'name' => 'shop',
            'volumes' => ['data' => ['name' => 'shop_data_custom'], 'cache' => []],
            'services' => [
                'web' => [
                    'build' => ['context' => '.'],
                    'ports' => [
                        ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
                        ['target' => 80, 'published' => '8081'],
                    ],
                    'volumes' => [
                        ['type' => 'volume', 'source' => 'data', 'target' => '/data'],
                        ['type' => 'volume', 'source' => 'cache', 'target' => '/cache'],
                        ['type' => 'volume', 'source' => '', 'target' => '/anon'],
                        ['type' => 'bind', 'source' => '/home/shop/project', 'target' => '/app'],
                        'not-an-entry',
                    ],
                ],
            ],
        ];

        $next = NextGeneration::plan($config, 8080, true);

        $this->assertInstanceOf(NextGeneration::class, $next);
        $this->assertSame([8080 => 80, 8081 => 80], $next->ports);
        $this->assertSame(<<<'YAML'
services:
  web:
    ports: !override
      -
        target: 80
        protocol: tcp
    labels:
      panelalpha.generation: next
    build: !reset null
    image: shop-web
networks:
  default: !override
    name: shop_default
    external: true
volumes:
  data: !override
    name: shop_data_custom
    external: true
  cache: !override
    name: shop_cache
    external: true

YAML, $next->override());
    }

    public function test_an_imaged_build_keeps_its_image_and_named_networks(): void
    {
        $config = [
            'name' => 'shop',
            'networks' => ['front' => ['name' => 'edge'], 'back' => []],
            'services' => [
                'web' => [
                    'build' => ['context' => '.'],
                    'image' => 'acme/web:1',
                    'networks' => ['front' => null, 'back' => ['aliases' => ['w']]],
                    'ports' => [['target' => 3000, 'published' => '3000', 'protocol' => 'TCP']],
                ],
            ],
        ];

        $this->assertSame(<<<'YAML'
services:
  web:
    ports: !override
      -
        target: 3000
        protocol: tcp
    labels:
      panelalpha.generation: next
    build: !reset null
    image: 'acme/web:1'
networks:
  front: !override
    name: edge
    external: true
  back: !override
    name: shop_back
    external: true

YAML, NextGeneration::plan($config, 3000, false)->override());
    }

    public function test_every_refusal_reads_as_before(): void
    {
        $port = ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'];

        $this->assertSame('its compose file could not be read', NextGeneration::plan(['name' => ' ', 'services' => ['a' => []]], 8080, false));
        $this->assertSame('its compose file could not be read', NextGeneration::plan(['name' => 'p', 'services' => []], 8080, false));
        $this->assertSame(
            'no service publishes port 8080, the one the site is routed to',
            NextGeneration::plan(['name' => 'p', 'services' => ['a' => ['ports' => [['target' => 80, 'published' => '9090']]], 'b' => 'x']], 8080, false)
        );
        $this->assertSame(
            'a shares another network namespace (network_mode: host)',
            NextGeneration::plan(['name' => 'p', 'services' => ['a' => ['ports' => [$port], 'network_mode' => 'host', 'container_name' => 'c']]], 8080, false)
        );
        $this->assertSame(
            'a names its container (c), and a name exists only once',
            NextGeneration::plan(['name' => 'p', 'services' => ['a' => ['ports' => [$port], 'container_name' => 'c']]], 8080, false)
        );
        $this->assertSame(
            'a publishes a port range or a UDP port',
            NextGeneration::plan(['name' => 'p', 'services' => ['a' => ['ports' => [$port, ['target' => 53, 'published' => '53', 'protocol' => 'udp']]]]], 8080, true)
        );
        $this->assertSame(
            'the repository\'s compose file runs 2 services, and only a single-service stack is started twice',
            NextGeneration::plan(['name' => 'p', 'services' => ['a' => ['ports' => [$port]], 'db' => []]], 8080, true)
        );
        $this->assertInstanceOf(NextGeneration::class, NextGeneration::plan(['name' => 'p', 'services' => ['a' => ['ports' => [$port]], 'db' => []]], 8080, false));
    }
}
