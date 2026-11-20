<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\CacheManager\BuildCacheBuilder;
use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildCacheFixtures;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;

class BuildCacheTest extends TestCase
{
    use BuildCacheFixtures;

    private const DIGEST = 'sha256:abcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabca';

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
}
