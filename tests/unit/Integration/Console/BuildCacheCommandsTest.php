<?php

namespace Tests\Integration\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `system:build-cache:*` against a cache registry answered by a scripted root broker: what is listed, what is
 * removed, and the plain line each refusal prints.
 */
class BuildCacheCommandsTest extends TestCase
{
    private ScriptedRoot $root;

    private string $lock;

    private string|false $previousLock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = new ScriptedRoot;
        $this->previousLock = getenv('PA_PUSH_LOCK');
        $this->lock = sys_get_temp_dir().'/pa-push-lock-'.bin2hex(random_bytes(4));
        putenv('PA_PUSH_LOCK='.$this->lock);
    }

    protected function tearDown(): void
    {
        putenv($this->previousLock === false ? 'PA_PUSH_LOCK' : 'PA_PUSH_LOCK='.$this->previousLock);
        @unlink($this->lock);
        $this->root->close();
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function run_(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    /** @param array<string, list<string>> $repositories repository => tags */
    private function registryHolds(array $repositories): void
    {
        $this->root->on('host.httpRequest', self::response(200, ['repositories' => array_keys($repositories)]), 0, '/v2/_catalog');
        foreach ($repositories as $repository => $tags) {
            $this->root->on('host.httpRequest', self::response(200, ['name' => $repository, 'tags' => $tags]), 0, "/v2/{$repository}/tags/list");
        }
    }

    private static function response(int $status, ?array $json = null, array $headers = []): string
    {
        $head = "HTTP/1.1 {$status} X\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head."\r\n".($json === null ? '' : json_encode($json));
    }

    public function test_the_listing_prints_each_build_cache_and_nothing_else(): void
    {
        $this->registryHolds([
            'buildcache/github.com/acme/app' => ['main', 'main-f0123abcd'],
            'panelalpha/php' => ['8.3-cli'],
        ]);

        $this->assertSame(
            [0, "buildcache/github.com/acme/app:main\nbuildcache/github.com/acme/app:main-f0123abcd\n"],
            $this->run_('system:build-cache:list')
        );
    }

    public function test_an_empty_registry_says_it_holds_no_build_cache(): void
    {
        $this->registryHolds(['panelalpha/php' => ['8.3-cli']]);

        $this->assertSame([0, "The cache registry holds no build cache.\n"], $this->run_('system:build-cache:list'));
    }

    public function test_a_registry_that_cannot_be_listed_fails_both_listing_and_removal(): void
    {
        $this->root->on('host.httpRequest', '', 7);

        $this->assertSame([1, "Could not list the cache registry.\n"], $this->run_('system:build-cache:list'));
        $this->assertSame([1, "Could not list the cache registry.\n"], $this->run_('system:build-cache:remove', ['repository' => 'https://github.com/acme/app']));
    }

    public function test_removing_a_branch_deletes_its_builds_by_digest_and_leaves_the_rest(): void
    {
        $this->registryHolds(['buildcache/github.com/acme/app' => ['main', 'main-f0123abcd', 'develop']]);
        $this->root->on('host.httpRequest', self::response(200, null, ['Docker-Content-Digest' => 'sha256:'.str_repeat('a', 64)]), 0, 'HEAD http');
        $this->root->on('host.httpRequest', self::response(202), 0, 'DELETE http');

        [$code, $out] = $this->run_('system:build-cache:remove', ['repository' => 'https://github.com/Acme/App.git', '--branch' => 'main']);

        $this->assertSame(0, $code, $out);
        $this->assertSame("removed buildcache/github.com/acme/app:main\nremoved buildcache/github.com/acme/app:main-f0123abcd\n", $out);
        $deletes = array_filter($this->root->requests(), static fn (array $argv): bool => in_array('DELETE', $argv, true));
        $this->assertCount(2, $deletes);
    }

    public function test_removing_a_repository_without_a_cache_says_so(): void
    {
        $this->registryHolds(['buildcache/github.com/other/app' => ['main']]);

        $this->assertSame([0, "No build cache for that repository.\n"], $this->run_('system:build-cache:remove', ['repository' => 'https://github.com/acme/app']));
    }

    public function test_a_url_that_is_not_plainly_public_is_refused_before_the_registry_is_asked(): void
    {
        [$code, $out] = $this->run_('system:build-cache:remove', ['repository' => 'https://oauth2:secret@gitlab.com/acme/app.git']);

        $this->assertSame(1, $code);
        $this->assertSame("Only a public https clone URL, with no user, token, port or query, can have a shared build cache.\n", $out);
        $this->assertSame([], $this->root->requests());
    }

    public function test_a_removal_that_cannot_take_the_push_lock_removes_nothing(): void
    {
        putenv('PA_PUSH_LOCK='.sys_get_temp_dir().'/pa-no-such-dir-'.bin2hex(random_bytes(3)).'/lock');
        $this->registryHolds(['buildcache/github.com/acme/app' => ['main']]);

        [$code, $out] = $this->run_('system:build-cache:remove', ['repository' => 'https://github.com/acme/app']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('nothing removed.', $out);
        $this->assertSame([], array_filter($this->root->requests(), static fn (array $argv): bool => in_array('DELETE', $argv, true)));
    }

    public function test_a_build_of_a_url_that_can_have_no_shared_cache_is_refused(): void
    {
        $this->assertSame(
            [1, "Only a public https clone URL can have a shared build cache.\n"],
            $this->run_('system:build-cache:build', ['repository' => ' git@github.com:acme/app.git ', '--branch' => ' '])
        );
        $this->assertSame([], $this->root->requests());
    }
}
