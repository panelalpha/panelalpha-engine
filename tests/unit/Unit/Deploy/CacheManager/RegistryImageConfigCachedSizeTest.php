<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use PHPUnit\Framework\TestCase;

/**
 * The bytes a pull from the cache registry alone moves, and the references no registry is asked about.
 */
class RegistryImageConfigCachedSizeTest extends TestCase
{
    private const CACHE = 'http://panelalpha-cache-registry:5000';

    private const PROXY = 'http://panelalpha-registry-proxy:5000';

    /**
     * @param  array<string, string>  $responses  URL to body; anything else fails
     * @param  list<string>  $asked
     */
    private function config(array $responses, array &$asked = []): RegistryImageConfig
    {
        return new RegistryImageConfig(
            static function (string $url) use ($responses, &$asked): ?string {
                $asked[] = $url;

                return $responses[$url] ?? null;
            },
            'amd64'
        );
    }

    public function test_the_cached_size_asks_the_cache_registry_and_never_the_proxy(): void
    {
        $asked = [];
        $config = $this->config([
            self::CACHE.'/v2/node/manifests/22' => (string) json_encode(['layers' => [['size' => 40], ['size' => 2], ['size' => 'x']]]),
            self::PROXY.'/v2/library/node/manifests/22' => (string) json_encode(['layers' => [['size' => 999]]]),
        ], $asked);

        $this->assertSame(42, $config->cachedDownloadBytes('node:22'));
        $this->assertSame([self::CACHE.'/v2/node/manifests/22'], $asked);
    }

    public function test_an_image_only_the_proxy_holds_has_no_cached_size(): void
    {
        $this->assertNull($this->config([
            self::PROXY.'/v2/library/node/manifests/22' => (string) json_encode(['layers' => [['size' => 999]]]),
        ])->cachedDownloadBytes('node:22'));
    }

    public function test_an_index_without_this_platform_has_no_size(): void
    {
        $asked = [];
        $bytes = $this->config([
            self::CACHE.'/v2/node/manifests/22' => (string) json_encode(['manifests' => [
                ['digest' => 'sha256:arm', 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
                'not an entry',
            ]]),
        ], $asked)->cachedDownloadBytes('node:22');

        $this->assertNull($bytes);
        $this->assertSame([self::CACHE.'/v2/node/manifests/22'], $asked);
    }

    public function test_an_unsafe_reference_is_asked_of_no_registry(): void
    {
        $this->assertSame([], RegistryImageConfig::sources('node:22; rm -rf /'));
        $this->assertSame([], RegistryImageConfig::sources('-rf'));
    }
}
