<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\ProjectCache;
use PHPUnit\Framework\TestCase;

/**
 * Host build containers run on the host daemon with a customer's repository
 * mounted in, so the sandboxing asserted here is the security boundary, not a
 * detail — every one of these assertions is something a rootless or Podman
 * implementation would owe as well.
 */
class DindHostBuilderTest extends TestCase
{
    private function builder(): DindHostBuilder
    {
        return new DindHostBuilder();
    }

    private function account(string $username, string $identity = '1001:1002'): EngineAccount
    {
        return new EngineAccount($username, '/home/' . $username, $identity);
    }

    public function test_docker_run_binds_project_and_isolated_js_cache_as_user(): void
    {
        $argv = $this->builder()->nodeBuildArgv(
            $this->account('landingpagebolt'),
            Images::NODE_IMAGE,
            'npm ci --no-audit --no-fund',
            'PATH=/app/node_modules/.bin:$PATH astro build',
            ['NITRO_PRESET' => 'node-server']
        );

        $this->assertSame('sudo', $argv[0]);
        $this->assertSame('docker', $argv[1]);
        $this->assertNotContains('--network', $argv);
        $this->assertContains('--user', $argv);
        $this->assertContains('1001:1002', $argv);
        $this->assertContains('no-new-privileges', $argv);
        $this->assertContains('--cap-drop', $argv);
        $this->assertContains('ALL', $argv);
        $this->assertContains('--pids-limit', $argv);
        $this->assertContains('/home/landingpagebolt/project:/app', $argv);
        $this->assertContains(ProjectCache::dirFor('landingpagebolt') . ':/var/cache/pa-js', $argv);
        $this->assertContains(ProjectCache::dirFor('landingpagebolt') . '/node_modules:/app/node_modules', $argv);
        $this->assertContains(Images::NODE_IMAGE, $argv);
        $this->assertContains('NITRO_PRESET=node-server', $argv);
        $this->assertStringContainsString('node_modules cache hit', implode(' ', $argv));
        $this->assertStringContainsString('npm ci --no-audit --no-fund', implode(' ', $argv));
        $this->assertStringContainsString('astro build', implode(' ', $argv));
    }

    public function test_standalone_node_compile_writes_node_modules_into_the_project(): void
    {
        $argv = $this->builder()->nodeBuildArgv(
            $this->account('saasstarterts', '1003:1003'),
            Images::NODE_IMAGE,
            'npm ci',
            'npx vite build',
            [],
            false
        );

        $this->assertContains('/home/saasstarterts/project:/app', $argv);
        $this->assertContains(ProjectCache::dirFor('saasstarterts') . ':/var/cache/pa-js', $argv);
        $this->assertNotContains(
            ProjectCache::dirFor('saasstarterts') . '/node_modules:/app/node_modules',
            $argv
        );
    }

    /** Group Office: the application is www/, the mount is still the whole checkout. */
    public function test_an_app_root_is_the_working_directory_not_the_mount(): void
    {
        $argv = $this->builder()->nodeBuildArgv(
            $this->account('groupoffice'),
            Images::NODE_IMAGE,
            'npm ci',
            'npm run build',
            [],
            true,
            true,
            'www'
        );
        $script = (string) end($argv);

        $this->assertContains('/home/groupoffice/project:/app', $argv);
        $this->assertSame('/app/www', $argv[array_search('-w', $argv, true) + 1]);
        $this->assertContains(ProjectCache::dirFor('groupoffice') . '/node_modules:/app/www/node_modules', $argv);
        $this->assertStringContainsString('if [ -f /app/www/package.json ]', $script);
        $this->assertStringContainsString('cmp -s /app/www/package-lock.json /app/www/node_modules/.pa-lock', $script);
        $this->assertStringNotContainsString(' /app/package.json', $script);
    }

    public function test_the_composer_pass_runs_in_the_app_root(): void
    {
        $argv = $this->builder()->composerInstallArgv($this->account('groupoffice'), '8.3', null, 'www');

        $this->assertContains('/home/groupoffice/project:/app', $argv);
        $this->assertSame('/app/www', $argv[array_search('-w', $argv, true) + 1]);
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

    public function test_prepare_cache_is_scoped_and_owned_by_build_user(): void
    {
        $command = $this->builder()->prepareCacheArgv($this->account('landingpagebolt'));
        $script = $command[array_key_last($command)];

        $this->assertSame(
            ['sudo', 'nsenter', '--target', '1', '--all', 'sh', '-c'],
            array_slice($command, 0, 7)
        );
        $this->assertStringContainsString('/var/cache/panelalpha/projects/landingpagebolt/node_modules', $script);
        $this->assertStringContainsString('/var/cache/panelalpha/projects/landingpagebolt/npm', $script);
        $this->assertStringContainsString('chown -R', $script);
        $this->assertStringContainsString('1001:1002', $script);
    }

    public function test_composer_uses_the_composer_image_not_bare_php_cli(): void
    {
        $argv = $this->builder()->composerInstallArgv($this->account('acme-shop'));

        $this->assertContains(Images::COMPOSER_IMAGE, $argv);
        $this->assertNotContains('php:8.5-apache-bookworm', $argv);
        $this->assertContains('--entrypoint', $argv);
        $this->assertContains('sh', $argv);
        $script = $argv[array_key_last($argv)];
        $this->assertStringContainsString('composer install', $script);
        $this->assertStringContainsString('--ignore-platform-reqs', $script);
        // Both run arbitrary PHP from the repo, and this build is on the host.
        $this->assertStringContainsString('--no-scripts', $script);
        $this->assertStringContainsString('--no-plugins', $script);
        $this->assertContains('/home/acme-shop/project:/app', $argv);
        $this->assertNotContains('--network', $argv);
        $this->assertContains('--user', $argv);
        $this->assertContains('1001:1002', $argv);
        $this->assertContains('no-new-privileges', $argv);
        $this->assertContains('--cap-drop', $argv);
        $this->assertContains('ALL', $argv);
        $this->assertContains('--pids-limit', $argv);
    }

    public function test_composer_rejects_paths_outside_home_project(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->composerInstallArgv(new EngineAccount('acme', '/srv/acme'));
    }
}
