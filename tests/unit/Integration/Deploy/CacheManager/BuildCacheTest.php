<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\CacheManager\BuildCacheBuilder;
use App\Lib\Deploy\CacheManager\BuildCacheProducer;
use App\System\Privileged;
use App\System\Project\Dind\Strategy\ProjectBuildCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;

class BuildCacheTest extends TestCase
{
    private const WORK = BuildCacheProducer::WORK_ROOT.'/0123456789ab';

    private const DIGEST = 'sha256:abcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabca';

    public function test_a_public_clone_url_names_its_cache_by_host_path_and_branch(): void
    {
        $this->assertSame(
            'buildcache/github.com/gethomepage/homepage:main',
            BuildCache::ref('https://github.com/gethomepage/homepage.git', 'main')
        );
    }

    /** Registry repository names are lowercase; GitHub owners are not. */
    public function test_the_path_is_lowercased(): void
    {
        $this->assertSame('github.com/home-assistant/core', BuildCache::repositoryPath('https://github.com/Home-Assistant/Core'));
    }

    public function test_the_default_branch_has_its_own_tag(): void
    {
        $this->assertSame('buildcache/github.com/acme/app:default', BuildCache::ref('https://github.com/acme/app', null));
        $this->assertSame('buildcache/github.com/acme/app:default', BuildCache::ref('https://github.com/acme/app', '  '));
    }

    /** @return iterable<string, array{string}> */
    public static function urlsWithoutASharedCache(): iterable
    {
        yield 'credentials' => ['https://oauth2:glpat-x@gitlab.com/acme/app.git'];
        yield 'user only' => ['https://bob@github.com/acme/app'];
        yield 'ssh' => ['git@github.com:acme/app.git'];
        yield 'plain http' => ['http://github.com/acme/app'];
        yield 'port' => ['https://git.example.com:8443/acme/app'];
        yield 'query' => ['https://github.com/acme/app?token=x'];
        yield 'no repository' => ['https://github.com/acme'];
        yield 'path a registry refuses' => ['https://github.com/acme/my app'];
    }

    #[DataProvider('urlsWithoutASharedCache')]
    public function test_a_source_that_is_not_plainly_public_has_no_cache(string $url): void
    {
        $this->assertNull(BuildCache::ref($url, 'main'));
    }

    public function test_a_branch_a_tag_cannot_hold_keeps_a_distinct_name(): void
    {
        $slash = BuildCache::tag('feature/login');
        $dash = BuildCache::tag('feature-login');

        $this->assertMatchesRegularExpression('/^feature-login-[0-9a-f]{8}$/', (string) $slash);
        $this->assertSame('feature-login', $dash);
        $this->assertNotSame($slash, $dash);
    }

    public function test_another_dockerfile_gets_another_tag(): void
    {
        $root = BuildCache::tag('main', 'Dockerfile');
        $nested = BuildCache::tag('main', 'docker/Dockerfile');

        $this->assertSame('main', $root);
        $this->assertMatchesRegularExpression('/^main-f[0-9a-f]{8}$/', (string) $nested);
    }

    public function test_a_tag_never_passes_the_registry_limit(): void
    {
        $tag = (string) BuildCache::tag(str_repeat('a', 300));

        $this->assertLessThanOrEqual(128, strlen($tag));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $tag);
    }

    public function test_accounts_import_from_the_read_only_registry_and_the_host_publishes_to_the_writer(): void
    {
        $ref = 'buildcache/github.com/acme/app:main';

        $this->assertSame('type=registry,ref=panelalpha-cache-registry:5000/'.$ref, BuildCache::cacheFrom($ref));
        $this->assertSame(TestNetwork::LOOPBACK.':5000/'.$ref, BuildCache::hostRef($ref));
    }

    public function test_existence_is_asked_of_the_read_only_registry(): void
    {
        $asked = [];
        $cache = new BuildCache(function (string $url) use (&$asked): bool {
            $asked[] = $url;

            return true;
        });

        $this->assertTrue($cache->exists('buildcache/github.com/acme/app:main'));
        $this->assertSame(['http://panelalpha-cache-registry:5000/v2/buildcache/github.com/acme/app/manifests/main'], $asked);
        $this->assertFalse((new BuildCache(fn (): bool => true))->exists('buildcache/github.com/acme/app'));
    }

    public function test_the_build_runs_isolated_with_the_checkout_read_only_and_no_image_output(): void
    {
        $request = (new BuildCacheBuilder('panelalpha-build'))->buildArgv(
            self::WORK.'/src',
            self::WORK.'/out-0000000f',
            ['context' => '', 'dockerfile' => 'docker/Dockerfile', 'target' => null, 'args' => ['VERSION' => '1']]
        );
        $build = LocalRoot::buildKit($request);
        $line = implode(' ', $build['arguments']);

        $this->assertSame('build-cache.buildKit', LocalRoot::action($request));
        $this->assertSame('panelalpha-build', $build['network']);
        $this->assertContains(self::WORK.'/src:/src:ro', $build['mounts']);
        $this->assertStringContainsString('--opt filename=docker/Dockerfile', $line);
        $this->assertStringContainsString('--opt build-arg:VERSION=1', $line);
        $this->assertStringContainsString('--export-cache type=local,dest=/out,mode=max', $line);
        $this->assertStringNotContainsString('--output', $line);
        $this->assertStringNotContainsString('docker.sock', $line);
        $this->assertStringNotContainsString('--privileged', $line);
        $this->assertSame([], LocalRoot::plain($request));
    }

    public function test_publishing_copies_the_layout_to_the_writer_and_runs_no_repository_code(): void
    {
        $request = Privileged::scripts()->publishBuildCache(self::WORK.'/out-0000000f', BuildCache::hostRef('buildcache/github.com/acme/app:main'));

        $this->assertSame('scripts.publishBuildCache', LocalRoot::action($request));
        $this->assertSame(
            [self::WORK.'/out-0000000f', BuildCache::hostRef('buildcache/github.com/acme/app:main')],
            LocalRoot::values($request)
        );
        $this->assertSame([], LocalRoot::plain($request));
    }

    public function test_a_compose_service_builds_in_its_own_context_and_stage(): void
    {
        $build = LocalRoot::buildKit((new BuildCacheBuilder(null))->buildArgv(
            self::WORK.'/src',
            self::WORK.'/out-00000001',
            ['context' => 'web', 'dockerfile' => 'Dockerfile.prod', 'target' => 'runtime', 'args' => []]
        ));
        $line = implode(' ', $build['arguments']);

        $this->assertStringContainsString('--local context=/src/web --local dockerfile=/src/web', $line);
        $this->assertStringContainsString('--opt filename=Dockerfile.prod --opt target=runtime', $line);
        $this->assertContains(self::WORK.'/out-00000001:/out', $build['mounts']);
    }

    /** An operator image exists on no registry the build can reach, so it comes in as a layout. */
    public function test_an_operator_base_is_a_read_only_named_layout_context(): void
    {
        $builder = new BuildCacheBuilder(null);
        $build = LocalRoot::buildKit($builder->buildArgv(
            self::WORK.'/src',
            self::WORK.'/out-0000000f',
            ['context' => '', 'dockerfile' => 'Dockerfile', 'target' => null, 'args' => []],
            ['acme/base:1.2' => ['dir' => self::WORK.'/layout-0000000a', 'digest' => self::DIGEST]]
        ));
        $line = implode(' ', $build['arguments']);

        $this->assertContains(self::WORK.'/layout-0000000a:/layouts/base0:ro', $build['mounts']);
        $this->assertStringContainsString('--oci-layout base0=/layouts/base0 --opt context:acme/base:1.2=oci-layout://base0:1.2@'.self::DIGEST, $line);
        $export = $builder->exportLayoutArgv('acme/base:1.2', self::WORK.'/layout-0000000a');
        $this->assertSame('build-cache.copyImageToLayout', LocalRoot::action($export));
        $this->assertSame(
            [TestNetwork::LOOPBACK.':5000', TestNetwork::LOOPBACK.':5000/acme/base:1.2', self::WORK.'/layout-0000000a', '1.2'],
            LocalRoot::values($export)
        );
        $this->assertSame([], LocalRoot::plain($export));
        $this->assertSame('latest', BuildCacheBuilder::layoutTag('registry.example.com:5000/acme/base'));
        $index = json_encode(['manifests' => [
            ['digest' => 'sha256:other', 'annotations' => ['org.opencontainers.image.ref.name' => '1.1']],
            ['digest' => 'sha256:wanted', 'annotations' => ['org.opencontainers.image.ref.name' => '1.2']],
        ]]);
        $this->assertSame('sha256:wanted', BuildCacheBuilder::layoutDigest((string) $index, '1.2'));
        $this->assertNull(BuildCacheBuilder::layoutDigest((string) $index, '2'));
    }

    /** The account runs `railpack prepare .` and `buildx -f plan --build-arg BUILDKIT_SYNTAX=…`; so does the host. */
    public function test_a_railpack_plan_is_prepared_and_built_the_way_the_account_does(): void
    {
        $builder = new BuildCacheBuilder('panelalpha-build');
        $prepare = $builder->railpackPrepareArgv('ghcr.io/panelalpha/engine-user-dind:v1', self::WORK.'/src', self::WORK.'/plan');
        $build = LocalRoot::buildKit($builder->railpackBuildArgv(self::WORK.'/src', self::WORK.'/plan', self::WORK.'/out-0000000f'));
        $line = implode(' ', $build['arguments']);

        $this->assertSame('build-cache.railpackPrepare', LocalRoot::action($prepare));
        $this->assertSame(
            ['panelalpha-build', '4g', '4096', 'ghcr.io/panelalpha/engine-user-dind:v1', self::WORK.'/src', self::WORK.'/plan'],
            LocalRoot::values($prepare)
        );
        $this->assertStringContainsString('--local context=/src --local dockerfile=/plan --opt filename=railpack-plan-tmp.json', $line);
        $this->assertStringContainsString('--opt build-arg:BUILDKIT_SYNTAX=ghcr.io/railwayapp/railpack-frontend', $line);
        $this->assertContains(self::WORK.'/src:/src:ro', $build['mounts']);
        $this->assertContains(self::WORK.'/plan:/plan:ro', $build['mounts']);
    }

    public function test_only_build_caches_are_cache_refs(): void
    {
        $this->assertTrue(BuildCache::isCacheRef('buildcache/github.com/acme/app:main'));
        $this->assertFalse(BuildCache::isCacheRef('panelalpha/php:8.3-pa1'));
        $this->assertFalse(BuildCache::isCacheRef('acme/buildcache:1'));
    }

    /** A deploy records the clone's branch before it builds, so the cache is named by it too. */
    public function test_the_cache_takes_the_name_of_the_branch_a_clone_checked_out(): void
    {
        $this->assertSame(['git', '-C', '/w/src', 'symbolic-ref', '--short', 'HEAD'], (new BuildCacheBuilder(null))->headBranchArgv('/w/src'));
        $this->assertSame('master', ProjectBuildCache::checkedOutBranch("ref: refs/heads/master\n"));
        $this->assertSame('release/2.x', ProjectBuildCache::checkedOutBranch('ref: refs/heads/release/2.x'));
        $this->assertNull(ProjectBuildCache::checkedOutBranch('d255d0cd4d69ce818574cfdb3a7e82407e6559d7'));
        $this->assertNull(ProjectBuildCache::checkedOutBranch(null));
    }

    public function test_the_clone_is_shallow_and_runs_no_hooks(): void
    {
        $argv = (new BuildCacheBuilder(null))->cloneArgv('https://github.com/acme/app', 'main', '/w/src');

        $this->assertSame(
            ['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--quiet', '--depth', '1', '--single-branch', '--branch', 'main', '--', 'https://github.com/acme/app', '/w/src'],
            $argv
        );
    }
}
