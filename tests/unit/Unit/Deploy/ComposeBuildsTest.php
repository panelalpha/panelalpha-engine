<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\ComposeBuilds;
use PHPUnit\Framework\TestCase;

class ComposeBuildsTest extends TestCase
{
    public function test_every_build_from_the_checkout_is_listed_with_what_keys_its_cache(): void
    {
        $builds = ComposeBuilds::of(['services' => [
            'app' => ['build' => '.'],
            'worker' => ['build' => ['context' => './worker/', 'dockerfile' => 'Dockerfile.prod', 'target' => 'run', 'args' => ['MODE=prod', 'LEVEL' => 3]]],
            'db' => ['image' => 'postgres:17'],
        ]]);

        $this->assertSame([
            'app' => ['context' => '', 'dockerfile' => 'Dockerfile', 'target' => null, 'args' => []],
            'worker' => ['context' => 'worker', 'dockerfile' => 'Dockerfile.prod', 'target' => 'run', 'args' => ['MODE' => 'prod', 'LEVEL' => '3']],
        ], $builds);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function buildsTheHostCannotReproduce(): iterable
    {
        yield 'context outside the checkout' => [['context' => '../shared']];
        yield 'remote context' => [['context' => 'https://github.com/acme/app.git#main']];
        yield 'absolute dockerfile' => [['context' => '.', 'dockerfile' => '/etc/Dockerfile']];
        yield 'inline dockerfile' => [['context' => '.', 'dockerfile_inline' => 'FROM alpine']];
        yield 'secrets' => [['context' => '.', 'secrets' => ['npmrc']]];
        yield 'ssh' => [['context' => '.', 'ssh' => ['default']]];
        yield 'an arg from the environment' => [['context' => '.', 'args' => ['VERSION' => '${APP_VERSION}']]];
        yield 'an interpolated context' => [['context' => '${APP_DIR}']];
    }

    /**
     * @param  array<string, mixed>  $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('buildsTheHostCannotReproduce')]
    public function test_a_build_the_host_cannot_reproduce_gets_no_cache(array $build): void
    {
        $this->assertSame([], ComposeBuilds::of(['services' => ['app' => ['build' => $build]]]));
    }

    /** A compose build at the root is the build a repository Dockerfile deploy runs: one cache for both. */
    public function test_a_root_build_shares_the_repository_dockerfiles_identity(): void
    {
        $this->assertSame('Dockerfile', ComposeBuilds::identity('', 'Dockerfile'));
        $this->assertSame('docker/Dockerfile', ComposeBuilds::identity('', 'docker/Dockerfile'));
        $this->assertSame('docker//Dockerfile', ComposeBuilds::identity('docker', 'Dockerfile'));
        $this->assertSame('web//Dockerfile@run', ComposeBuilds::identity('web', 'Dockerfile', 'run'));
    }

    public function test_the_cache_is_added_beside_the_projects_own_cache_from(): void
    {
        $from = ['type=registry,ref=panelalpha-cache-registry:5000/buildcache/github.com/acme/app:main'];

        $this->assertSame(['context' => '.', 'cache_from' => $from], ComposeBuilds::withCacheFrom('.', $from));
        $this->assertSame(
            ['context' => '.', 'cache_from' => ['type=local,src=/tmp/cache', $from[0]]],
            ComposeBuilds::withCacheFrom(['context' => '.', 'cache_from' => ['type=local,src=/tmp/cache']], $from)
        );
    }
}
