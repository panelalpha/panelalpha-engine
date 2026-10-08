<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reading an image's declared ports from a registry, without pulling it: what
 * sidecar classification uses now that the host no longer pulls on a deploy's behalf.
 */
class RegistryImageConfigTest extends TestCase
{
    private const CACHE = 'http://panelalpha-cache-registry:5000';
    private const PROXY = 'http://panelalpha-registry-proxy:5000';

    /**
     * @param array<string, string> $responses URL to body; anything else fails
     * @param list<string> $asked
     */
    private function config(array $responses, array &$asked = []): RegistryImageConfig
    {
        return new RegistryImageConfig(
            static function (string $url, string $accept) use ($responses, &$asked): ?string {
                $asked[] = $url;

                return $responses[$url] ?? null;
            },
            'amd64'
        );
    }

    public function test_a_hub_image_is_read_through_the_proxy_under_library(): void
    {
        $asked = [];
        $ports = $this->config([
            self::PROXY . '/v2/library/postgres/manifests/16' => json_encode([
                'manifests' => [
                    ['digest' => 'sha256:arm', 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
                    ['digest' => 'sha256:amd', 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
                ],
            ]),
            self::PROXY . '/v2/library/postgres/manifests/sha256:amd' => json_encode(['config' => ['digest' => 'sha256:cfg']]),
            self::PROXY . '/v2/library/postgres/blobs/sha256:cfg' => json_encode(['config' => ['ExposedPorts' => ['5432/tcp' => []]]]),
        ], $asked)->exposedPorts('postgres:16');

        $this->assertSame([5432], $ports);
        $this->assertSame(self::CACHE . '/v2/postgres/manifests/16', $asked[0], 'the cache registry is asked first');
    }

    public function test_an_images_own_environment_is_read_from_its_config(): void
    {
        $env = $this->config([
            self::PROXY . '/v2/sharelatex/sharelatex/manifests/6.3.0' => json_encode(['config' => ['digest' => 'sha256:c']]),
            self::PROXY . '/v2/sharelatex/sharelatex/blobs/sha256:c' => json_encode(['config' => ['Env' => [
                'PATH=/usr/bin',
                'NODE_OPTIONS=--require /overleaf/.pnp.cjs --import /overleaf/.pnp.register.mjs',
            ]]]),
        ])->environment('sharelatex/sharelatex:6.3.0');

        $this->assertSame(['PATH=/usr/bin', 'NODE_OPTIONS=--require /overleaf/.pnp.cjs --import /overleaf/.pnp.register.mjs'], $env);
        $this->assertSame([], $this->config([])->environment('sharelatex/sharelatex:6.3.0'));
    }

    public function test_the_cache_registry_answers_under_the_pushed_name(): void
    {
        $ports = $this->config([
            self::CACHE . '/v2/myorg/our-postgres/manifests/2' => json_encode(['config' => ['digest' => 'sha256:c']]),
            self::CACHE . '/v2/myorg/our-postgres/blobs/sha256:c' => json_encode(['config' => ['ExposedPorts' => ['5432/tcp' => [], '9187/tcp' => []]]]),
        ])->exposedPorts('myorg/our-postgres:2');

        $this->assertSame([5432, 9187], $ports);
    }

    public function test_another_registry_is_never_proxied(): void
    {
        $asked = [];
        $this->config([], $asked)->exposedPorts('ghcr.io/kanbn/kan:1');

        $this->assertSame([self::CACHE . '/v2/ghcr.io/kanbn/kan/manifests/1'], $asked);
    }

    public function test_an_explicit_docker_io_prefix_is_still_hub(): void
    {
        $sources = RegistryImageConfig::sources('docker.io/valkey/valkey:8');

        $this->assertSame([self::PROXY, 'valkey/valkey', '8'], $sources[1]);
    }

    public function test_a_digest_is_the_reference(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);

        $this->assertSame([self::PROXY, 'library/redis', $digest], RegistryImageConfig::sources("redis@{$digest}")[1]);
    }

    public function test_a_tag_beside_a_digest_stays_out_of_the_path(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);

        $this->assertSame([
            [self::CACHE, 'sharelatex/sharelatex', $digest],
            [self::PROXY, 'sharelatex/sharelatex', $digest],
        ], RegistryImageConfig::sources("sharelatex/sharelatex:6.3.0@{$digest}"));
        $this->assertSame(
            [self::CACHE, 'registry.internal:5000/ns/pg', $digest],
            RegistryImageConfig::sources("registry.internal:5000/ns/pg:16@{$digest}")[0]
        );
    }

    public function test_a_pinned_image_declares_its_ports(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $ports = $this->config([
            self::PROXY . "/v2/traefik/whoami/manifests/{$digest}" => json_encode(['config' => ['digest' => 'sha256:c']]),
            self::PROXY . '/v2/traefik/whoami/blobs/sha256:c' => json_encode(['config' => ['ExposedPorts' => ['80/tcp' => []]]]),
        ])->exposedPorts("traefik/whoami:v1.10.3@{$digest}");

        $this->assertSame([80], $ports);
    }

    public function test_a_private_registry_host_with_a_port_is_not_hub(): void
    {
        $this->assertCount(1, RegistryImageConfig::sources('localhost:5000/x:1'));
    }

    public function test_download_size_is_this_platforms_layers_through_the_proxy(): void
    {
        $bytes = $this->config([
            self::PROXY . '/v2/library/node/manifests/20' => json_encode(['manifests' => [
                ['digest' => 'sha256:arm', 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
                ['digest' => 'sha256:amd', 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
            ]]),
            self::PROXY . '/v2/library/node/manifests/sha256:amd' => json_encode(['layers' => [['size' => 30], ['size' => 12]]]),
            // Summing every platform would multiply the answer.
            self::PROXY . '/v2/library/node/manifests/sha256:arm' => json_encode(['layers' => [['size' => 999]]]),
        ])->downloadBytes('node:20');

        $this->assertSame(42, $bytes);
    }

    public function test_download_size_asks_the_cache_registry_first(): void
    {
        $asked = [];
        $bytes = $this->config([
            self::CACHE . '/v2/composer/manifests/2' => json_encode(['layers' => [['size' => 7]]]),
        ], $asked)->downloadBytes('composer:2');

        $this->assertSame(7, $bytes);
        $this->assertSame([self::CACHE . '/v2/composer/manifests/2'], $asked, 'no Hub request when the cache answers');
    }

    public function test_an_unknown_size_is_null_for_the_caller_to_fall_back(): void
    {
        $this->assertNull($this->config([])->downloadBytes('ghcr.io/railwayapp/railpack-builder:mise-1'));
    }

    public function test_nothing_found_is_no_ports_not_an_error(): void
    {
        $this->assertSame([], $this->config([])->exposedPorts('redis:alpine'));
        $this->assertSame([], $this->config([])->exposedPorts('-rf'));
    }
}
