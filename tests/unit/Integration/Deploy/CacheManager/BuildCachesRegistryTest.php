<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\CacheManager\BuildCaches;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use Tests\TestCase;

/**
 * Listing and removing a repository's build caches against what the cache registry holds.
 */
class BuildCachesRegistryTest extends TestCase
{
    private const BASE = 'http://registry.test';

    /** @var list<string> */
    private array $sent = [];

    private ?string $lock = null;

    protected function tearDown(): void
    {
        if ($this->lock !== null) {
            putenv('PA_PUSH_LOCK='.$this->lock);
        }
        parent::tearDown();
    }

    /**
     * @param  array<string, ?string>|null  $tags  ref => digest (null: no digest), null when the registry cannot be listed
     * @param  list<string>  $refused  refs whose manifest the registry will not delete
     */
    private function caches(?array $tags, array $refused = []): BuildCaches
    {
        return new BuildCaches(new CacheRegistry(function (string $method, string $url) use ($tags, $refused): ?array {
            $this->sent[] = "{$method} {$url}";
            if ($tags === null) {
                return null;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            $split = static fn (string $ref): array => [substr($ref, 0, (int) strrpos($ref, ':')), substr($ref, (int) strrpos($ref, ':') + 1)];
            if ($path === '/v2/_catalog') {
                $repositories = array_values(array_unique(array_map(static fn (string $ref): string => $split($ref)[0], array_keys($tags))));

                return ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['repositories' => $repositories])];
            }
            if (preg_match('#^/v2/(.+)/tags/list$#', $path, $m) === 1) {
                $listed = [];
                foreach (array_keys($tags) as $ref) {
                    if ($split($ref)[0] === $m[1]) {
                        $listed[] = $split($ref)[1];
                    }
                }

                return ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['tags' => $listed])];
            }
            preg_match('#^/v2/(.+)/manifests/(.+)$#', $path, $m);
            if ($method === 'DELETE') {
                foreach ($tags as $ref => $digest) {
                    if ($split($ref)[0] === $m[1] && $digest === $m[2]) {
                        return ['status' => in_array($ref, $refused, true) ? 405 : 202, 'headers' => [], 'body' => ''];
                    }
                }

                return ['status' => 404, 'headers' => [], 'body' => ''];
            }
            $digest = $tags["{$m[1]}:{$m[2]}"] ?? null;

            return $digest === null
                ? ['status' => 404, 'headers' => [], 'body' => '']
                : ['status' => 200, 'headers' => ['docker-content-digest' => $digest], 'body' => ''];
        }, self::BASE));
    }

    public function test_only_build_caches_are_listed_sorted_with_their_repository_and_tag(): void
    {
        $caches = $this->caches([
            'buildcache/github.com/acme/web:main' => 'sha256:b',
            'node:22' => 'sha256:n',
            'buildcache/github.com/acme/api:default' => 'sha256:a',
        ])->all();

        $this->assertSame([
            ['ref' => 'buildcache/github.com/acme/api:default', 'repository' => 'github.com/acme/api', 'tag' => 'default'],
            ['ref' => 'buildcache/github.com/acme/web:main', 'repository' => 'github.com/acme/web', 'tag' => 'main'],
        ], $caches);
    }

    public function test_an_unreadable_registry_cannot_be_listed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not list the cache registry.');

        $this->caches(null)->all();
    }

    public function test_removing_a_branch_deletes_its_caches_and_leaves_the_rest(): void
    {
        $main = (string) BuildCache::ref('https://github.com/acme/web', 'main');
        $mainWorker = (string) BuildCache::ref('https://github.com/acme/web', 'main', 'worker/Dockerfile');
        $dev = (string) BuildCache::ref('https://github.com/acme/web', 'dev');

        $removed = $this->caches([$main => 'sha256:m', $mainWorker => 'sha256:w', $dev => 'sha256:d'])
            ->remove('https://github.com/acme/web', ' main ');

        $this->assertSame([$main, $mainWorker], $removed);
        $this->assertContains('DELETE '.self::BASE.'/v2/buildcache/github.com/acme/web/manifests/sha256:m', $this->sent);
        $this->assertNotContains('DELETE '.self::BASE.'/v2/buildcache/github.com/acme/web/manifests/sha256:d', $this->sent);
    }

    public function test_removing_every_branch_reports_only_what_the_registry_let_go(): void
    {
        $main = (string) BuildCache::ref('https://github.com/acme/web', 'main');
        $dev = (string) BuildCache::ref('https://github.com/acme/web', 'dev');
        $other = (string) BuildCache::ref('https://github.com/acme/other', 'main');

        $removed = $this->caches([$main => 'sha256:m', $dev => 'sha256:d', $other => 'sha256:o'], refused: [$dev])
            ->remove('https://github.com/acme/web', '');

        $this->assertSame([$main], $removed);
    }

    public function test_a_repository_with_no_caches_removes_nothing_and_takes_no_lock(): void
    {
        $this->lock = (string) getenv('PA_PUSH_LOCK');
        putenv('PA_PUSH_LOCK='.sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'/lock');

        $this->assertSame([], $this->caches(['node:22' => 'sha256:n'])->remove('https://github.com/acme/web', null));
    }

    public function test_a_lock_that_cannot_be_taken_removes_nothing(): void
    {
        $this->lock = (string) getenv('PA_PUSH_LOCK');
        putenv('PA_PUSH_LOCK='.sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'/lock');
        $main = (string) BuildCache::ref('https://github.com/acme/web', 'main');

        try {
            $this->caches([$main => 'sha256:m'])->remove('https://github.com/acme/web', 'main');
            $this->fail('an unlockable registry should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringEndsWith('; nothing removed.', $e->getMessage());
        }
        $this->assertSame([], array_filter($this->sent, static fn (string $request): bool => str_starts_with($request, 'DELETE ')));
    }

    public function test_removing_the_caches_of_a_private_url_is_refused_before_the_registry_is_asked(): void
    {
        try {
            $this->caches([])->remove('https://bob:secret@github.com/acme/web', null);
            $this->fail('a URL with credentials has no shared cache');
        } catch (ProblemException $e) {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_make_talks_to_the_writer(): void
    {
        $this->assertInstanceOf(BuildCaches::class, BuildCaches::make());
    }
}
