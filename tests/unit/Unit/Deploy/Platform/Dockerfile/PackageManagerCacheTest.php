<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\PackageManagerCache;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use PHPUnit\Framework\TestCase;

class PackageManagerCacheTest extends TestCase
{
    public function test_every_known_package_manager_mounts_its_own_cache_directory(): void
    {
        foreach (['npm', 'yarn', 'pnpm', 'bun', 'pip'] as $manager) {
            $mount = PackageManagerCache::mountFor($manager);
            $directory = PackageManagerCache::directoryFor($manager);

            $this->assertNotNull($directory);
            $this->assertStringContainsString('--mount=type=cache', $mount);
            $this->assertStringContainsString('target=' . $directory, $mount);
            // npm and yarn are not safe against another build mutating their
            // cache underneath them, and BuildKit's default permits it.
            $this->assertStringContainsString('sharing=locked', $mount);
            $this->assertStringEndsWith(' ', $mount, 'concatenated straight onto the command');
        }
    }

    public function test_an_unknown_toolchain_keeps_the_build_it_has_today(): void
    {
        $this->assertSame('', PackageManagerCache::mountFor('cargo'));
        $this->assertSame('', PackageManagerCache::mountFor(''));
        $this->assertNull(PackageManagerCache::directoryFor('cargo'));
    }

    /**
     * The measured problem: yarn's 1.6 GB cache lived in the install layer.
     * The mount is what keeps it out.
     */
    public function test_the_node_install_layer_mounts_the_cache_instead_of_committing_it(): void
    {
        $dockerfile = DockerfileBuilder::generate([
            'runtime' => 'node',
            'image' => 'node:20-bookworm-slim',
            'install_command' => 'yarn install --frozen-lockfile',
            'package_manager' => 'yarn',
            'port_hint' => 3000,
        ], ['package.json' => true, 'yarn.lock' => true]);

        $this->assertMatchesRegularExpression(
            '/RUN --mount=type=cache,target=\/usr\/local\/share\/\.cache\/yarn,sharing=locked yarn install/',
            $dockerfile
        );
    }

    public function test_npm_gets_its_own_directory_not_yarns(): void
    {
        $dockerfile = DockerfileBuilder::generate([
            'runtime' => 'node',
            'image' => 'node:20-bookworm-slim',
            'install_command' => 'npm ci --no-audit --no-fund',
            'package_manager' => 'npm',
        ], ['package.json' => true, 'package-lock.json' => true]);

        $this->assertStringContainsString('target=/root/.npm', $dockerfile);
        $this->assertStringNotContainsString('.cache/yarn', $dockerfile);
    }

    /**
     * `--no-cache-dir` tells pip to write no cache at all, so mounting one
     * would be a mount over a directory nothing writes. Dropping that flag is
     * a manifest decision with its own measurement, not a side effect of this.
     */
    public function test_pip_is_left_alone_while_the_manifest_disables_its_cache(): void
    {
        $withFlag = DockerfileBuilder::generate([
            'runtime' => 'command',
            'image' => 'python:3.12-slim',
            'install_command' => 'pip install --no-cache-dir -r requirements.txt',
        ], []);
        $this->assertStringNotContainsString('--mount=type=cache', $withFlag);

        $withoutFlag = DockerfileBuilder::generate([
            'runtime' => 'command',
            'image' => 'python:3.12-slim',
            'install_command' => 'pip install -r requirements.txt',
        ], []);
        $this->assertStringContainsString('target=/root/.cache/pip', $withoutFlag);
    }

    public function test_a_non_python_command_recipe_is_untouched(): void
    {
        $go = DockerfileBuilder::generate([
            'runtime' => 'command',
            'image' => 'golang:1.22',
            'install_command' => 'go mod download',
        ], []);

        $this->assertStringNotContainsString('--mount=type=cache', $go);
        $this->assertStringContainsString('go mod download', $go);
    }
}
