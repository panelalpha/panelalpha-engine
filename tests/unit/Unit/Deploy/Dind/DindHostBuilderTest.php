<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Engine\EngineAccount;
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

    public function test_standalone_node_compile_writes_node_modules_into_the_project(): void
    {
        $build = LocalRoot::build($this->builder()->nodeBuildArgv(
            $this->account('saasstarterts', '1003:1003'),
            Images::NODE_IMAGE,
            'npm ci',
            'npx vite build',
            [],
            false
        ));

        $this->assertContains('/home/saasstarterts/project:/app', $build['mounts']);
        $this->assertContains(ProjectCache::dirFor('saasstarterts').':/var/cache/pa-js', $build['mounts']);
        $this->assertNotContains(
            ProjectCache::dirFor('saasstarterts').'/node_modules:/app/node_modules',
            $build['mounts']
        );
    }

    /** Group Office: the application is www/, the mount is still the whole checkout. */
    public function test_an_app_root_is_the_working_directory_not_the_mount(): void
    {
        $build = LocalRoot::build($this->builder()->nodeBuildArgv(
            $this->account('groupoffice'),
            Images::NODE_IMAGE,
            'npm ci',
            'npm run build',
            [],
            true,
            true,
            'www'
        ));
        $script = $build['script'];

        $this->assertContains('/home/groupoffice/project:/app', $build['mounts']);
        $this->assertSame('/app/www', $build['workdir']);
        $this->assertContains(ProjectCache::dirFor('groupoffice').'/node_modules:/app/www/node_modules', $build['mounts']);
        $this->assertStringContainsString('if [ -f /app/www/package.json ]', $script);
        $this->assertStringContainsString('cmp -s /app/www/package-lock.json /app/www/node_modules/.pa-lock', $script);
        $this->assertStringNotContainsString(' /app/package.json', $script);
    }

    public function test_the_composer_pass_runs_in_the_app_root(): void
    {
        $build = LocalRoot::build($this->builder()->composerInstallArgv($this->account('groupoffice'), '8.3', null, 'www'));

        $this->assertContains('/home/groupoffice/project:/app', $build['mounts']);
        $this->assertSame('/app/www', $build['workdir']);
    }

    public function test_refuses_a_project_dir_outside_home_project(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->nodeBuildArgv(
            new EngineAccount('evil', '/srv/evil'),
            Images::NODE_IMAGE,
            'npm ci',
            'npm run build'
        );
    }

    public function test_refuses_an_unsafe_image_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->nodeBuildArgv(
            $this->account('landingpagebolt'),
            'node:20; rm -rf /',
            'npm ci',
            'npm run build'
        );
    }

    public function test_refuses_a_build_with_nothing_to_run(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->nodeBuildArgv(
            $this->account('landingpagebolt'),
            Images::NODE_IMAGE,
            '',
            ''
        );
    }

    public function test_composer_uses_the_composer_image_not_bare_php_cli(): void
    {
        $build = LocalRoot::build($this->builder()->composerInstallArgv($this->account('acme-shop')));

        $this->assertSame(Images::COMPOSER_IMAGE, $build['image']);
        $script = $build['script'];
        $this->assertStringContainsString('composer install', $script);
        $this->assertStringContainsString('--ignore-platform-reqs', $script);
        // Both run arbitrary PHP from the repo, and this build is on the host.
        $this->assertStringContainsString('--no-scripts', $script);
        $this->assertStringContainsString('--no-plugins', $script);
        $this->assertContains('/home/acme-shop/project:/app', $build['mounts']);
        $this->assertSame('', $build['network']);
        $this->assertSame('1001:1002', $build['user']);
    }

    public function test_composer_rejects_paths_outside_home_project(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->composerInstallArgv(new EngineAccount('acme', '/srv/acme'));
    }
}
