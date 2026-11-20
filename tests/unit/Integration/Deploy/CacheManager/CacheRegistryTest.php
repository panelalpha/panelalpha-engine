<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\Dind\DindImageStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * What prewarm keeps in panelalpha-cache-registry, and what it removes.
 */
class CacheRegistryTest extends TestCase
{
    /** The writer listens on the host's loopback only, which core's own network namespace is not. */
    public function test_requests_are_made_from_the_hosts_network_namespace(): void
    {
        $delete = CacheRegistry::hostArgv('DELETE', 'http://' . DindImageStore::HOST_CACHE_REGISTRY . '/v2/node/manifests/sha256:abc', []);
        $this->assertSame([LocalRoot::CLIENT, '--host'], array_slice($delete, 0, 2));
        $this->assertSame(
            ['curl', '-sS', '-i', '--connect-timeout', '2', '--max-time', '30',
                '-X', 'DELETE', '--', 'http://' . DindImageStore::HOST_CACHE_REGISTRY . '/v2/node/manifests/sha256:abc'],
            LocalRoot::plain($delete)
        );

        $head = LocalRoot::plain(CacheRegistry::hostArgv('HEAD', 'http://' . DindImageStore::HOST_CACHE_REGISTRY . '/v2/node/manifests/20', ['Accept' => 'a, b']));
        $this->assertContains('--head', $head);
        $this->assertNotContains('-X', $head);
        $this->assertSame(['-H', 'Accept: a, b', '--', 'http://' . DindImageStore::HOST_CACHE_REGISTRY . '/v2/node/manifests/20'], array_slice($head, -4));
    }
}
