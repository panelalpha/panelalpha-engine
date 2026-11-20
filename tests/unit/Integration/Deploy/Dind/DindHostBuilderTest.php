<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\ProjectCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\DindHostBuilderFixtures;
use Tests\Support\LocalRoot;

/**
 * Host build containers run on the host daemon with a customer's repository
 * mounted in, so the sandboxing asserted here is the security boundary, not a
 * detail — every one of these assertions is something a rootless or Podman
 * implementation would owe as well.
 */
class DindHostBuilderTest extends TestCase
{
    use DindHostBuilderFixtures;

    public function test_docker_run_binds_project_and_isolated_js_cache_as_user(): void
    {
        $request = $this->builder()->nodeBuildArgv(
            $this->account('landingpagebolt'),
            Images::NODE_IMAGE,
            'npm ci --no-audit --no-fund',
            'PATH=/app/node_modules/.bin:$PATH astro build',
            ['NITRO_PRESET' => 'node-server']
        );
        $build = LocalRoot::build($request);

        $this->assertSame('docker.nodeBuild', LocalRoot::action($request));
        $this->assertSame([], LocalRoot::plain($request));
        $this->assertSame('', $build['network']);
        $this->assertSame('1001:1002', $build['user']);
        $this->assertContains('/home/landingpagebolt/project:/app', $build['mounts']);
        $this->assertContains(ProjectCache::dirFor('landingpagebolt').':/var/cache/pa-js', $build['mounts']);
        $this->assertContains(ProjectCache::dirFor('landingpagebolt').'/node_modules:/app/node_modules', $build['mounts']);
        $this->assertSame(Images::NODE_IMAGE, $build['image']);
        $this->assertSame('node-server', $build['environment']['NITRO_PRESET'] ?? null);
        $this->assertSame('0', $build['environment']['COREPACK_ENABLE_AUTO_PIN'] ?? null, 'corepack wrote packageManager into the checkout');
        $this->assertStringContainsString('node_modules cache hit', $build['script']);
        $this->assertStringContainsString('npm ci --no-audit --no-fund', $build['script']);
        $this->assertStringContainsString('astro build', $build['script']);
    }

    public function test_prepare_cache_is_scoped_and_owned_by_build_user(): void
    {
        $request = $this->builder()->prepareCacheArgv($this->account('landingpagebolt'));
        $command = LocalRoot::plain($request);
        $script = $command[array_key_last($command)];

        $this->assertTrue(LocalRoot::isRequest($request));
        $this->assertSame('--host', $request[1]);
        $this->assertSame(['sh', '-c'], array_slice($command, 0, 2));
        $this->assertStringContainsString('/var/cache/panelalpha/projects/landingpagebolt/node_modules', $script);
        $this->assertStringContainsString('/var/cache/panelalpha/projects/landingpagebolt/npm', $script);
        $this->assertStringNotContainsString('chown -R', $script);
        $this->assertStringContainsString("chown -h -- '1001:1002' \"\$cache\"", $script);
    }
}
