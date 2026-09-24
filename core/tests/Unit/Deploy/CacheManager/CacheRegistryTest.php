<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What prewarm keeps in panelalpha-cache-registry, and what it removes.
 */
class CacheRegistryTest extends TestCase
{
    private const BASE = 'http://registry.test';

    /**
     * @param array<string, array{status: int, headers?: array<string, string>, body?: string}> $routes "METHOD url"
     * @param list<string> $sent
     */
    private function registry(array $routes, array &$sent = []): CacheRegistry
    {
        return new CacheRegistry(
            static function (string $method, string $url, array $headers) use ($routes, &$sent): ?array {
                $sent[] = "{$method} {$url}";
                $route = $routes["{$method} {$url}"] ?? null;

                return $route === null ? null : $route + ['headers' => [], 'body' => ''];
            },
            self::BASE
        );
    }

    public function test_it_lists_every_repository_and_tag(): void
    {
        $refs = $this->registry([
            'GET ' . self::BASE . '/v2/_catalog?n=1000' => ['status' => 200, 'body' => '{"repositories":["node","panelalpha/php","ghcr.io/railwayapp/railpack-builder"]}'],
            'GET ' . self::BASE . '/v2/node/tags/list' => ['status' => 200, 'body' => '{"name":"node","tags":["22-bookworm-slim","20"]}'],
            // A repository whose last tag was deleted lists null, not [].
            'GET ' . self::BASE . '/v2/panelalpha/php/tags/list' => ['status' => 200, 'body' => '{"name":"panelalpha/php","tags":null}'],
            'GET ' . self::BASE . '/v2/ghcr.io/railwayapp/railpack-builder/tags/list' => ['status' => 200, 'body' => '{"tags":["mise-1"]}'],
        ])->refs();

        $this->assertSame(['node:22-bookworm-slim', 'node:20', 'ghcr.io/railwayapp/railpack-builder:mise-1'], $refs);
    }

    public function test_the_catalogue_is_followed_page_by_page(): void
    {
        // Registry 3.x answers 400 to n above 1000 and links the next page.
        $refs = $this->registry([
            'GET ' . self::BASE . '/v2/_catalog?n=1000' => [
                'status' => 200,
                'headers' => ['link' => '</v2/_catalog?last=a&n=1000>; rel="next"'],
                'body' => '{"repositories":["a"]}',
            ],
            'GET ' . self::BASE . '/v2/_catalog?last=a&n=1000' => ['status' => 200, 'body' => '{"repositories":["b"]}'],
            'GET ' . self::BASE . '/v2/a/tags/list' => ['status' => 200, 'body' => '{"tags":["1"]}'],
            'GET ' . self::BASE . '/v2/b/tags/list' => ['status' => 200, 'body' => '{"tags":["2"]}'],
        ])->refs();

        $this->assertSame(['a:1', 'b:2'], $refs);
    }

    public function test_an_unreadable_registry_is_null_not_empty(): void
    {
        // Empty would read as "holds nothing", and nothing would be removed
        // either way, but the caller has to be able to say it could not look.
        $this->assertNull($this->registry([])->refs());
    }

    public function test_digest_and_delete_address_the_manifest(): void
    {
        $sent = [];
        $registry = $this->registry([
            'HEAD ' . self::BASE . '/v2/panelalpha/php/manifests/8.2-pa1' => ['status' => 200, 'headers' => ['docker-content-digest' => 'sha256:abc']],
            'DELETE ' . self::BASE . '/v2/panelalpha/php/manifests/sha256:abc' => ['status' => 202],
        ], $sent);

        $this->assertSame('sha256:abc', $registry->digest('panelalpha/php:8.2-pa1'));
        $this->assertTrue($registry->delete('panelalpha/php:8.2-pa1', 'sha256:abc'));
        $this->assertNull($registry->digest('panelalpha/php:gone'));
    }

    public function test_only_what_the_catalogue_does_not_keep_is_unwanted(): void
    {
        $unwanted = CacheRegistry::unwanted(
            [
                'node:22-bookworm-slim' => 'sha256:n22',
                'node:20' => 'sha256:n20',
                'panelalpha/php:8.2-pa-old' => 'sha256:old',
                'panelalpha/php:8.2-pa-new' => 'sha256:new',
            ],
            ['node:22-bookworm-slim', 'panelalpha/php:8.2-pa-new']
        );

        $this->assertSame(['node:20', 'panelalpha/php:8.2-pa-old'], $unwanted);
    }

    public function test_a_tag_sharing_a_kept_digest_is_never_removed(): void
    {
        // Deleting is by digest, so removing `node:22` would take the kept
        // `node:22-bookworm-slim` with it when both name one image.
        $unwanted = CacheRegistry::unwanted(
            ['node:22' => 'sha256:same', 'node:22-bookworm-slim' => 'sha256:same', 'gone:1' => null],
            ['node:22-bookworm-slim']
        );

        $this->assertSame([], $unwanted);
    }
}
