<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Dockerfile\BuildRecipe;
use App\Lib\Deploy\Platform\Dockerfile\NodeDockerfile;
use App\Lib\Deploy\Platform\Dockerfile\StaticSiteDockerfile;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use Tests\TestCase;

/**
 * Webpack 4 hashes with MD4, which OpenSSL 3 (Node 17+) refuses with
 * ERR_OSSL_EVP_UNSUPPORTED. React-Messenger-Clone (react-scripts 3.4.3) failed
 * that way on the default Node 22.
 */
class NodeLegacyOpensslTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/node-openssl-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function needs(array $package, ?string $lockName = null, string $lock = ''): bool
    {
        file_put_contents($this->dir . '/package.json', json_encode($package));
        if ($lockName !== null) {
            file_put_contents($this->dir . '/' . $lockName, $lock);
        }

        return NodeRuntime::needsLegacyOpenssl(ProjectContext::at($this->dir));
    }

    public function test_declared_webpack4_toolchains_need_it(): void
    {
        $this->assertTrue($this->needs(['dependencies' => ['react-scripts' => '3.4.3']]));
        $this->assertTrue($this->needs(['devDependencies' => ['@vue/cli-service' => '~4.5.0']]));
        $this->assertTrue($this->needs(['devDependencies' => ['webpack' => '^4.46.0']]));
        $this->assertTrue($this->needs(['devDependencies' => ['laravel-mix' => '^5.0.1']]));
        $this->assertTrue($this->needs(['dependencies' => ['nuxt' => '^2.15.8']]));
    }

    public function test_webpack5_toolchains_do_not(): void
    {
        $this->assertFalse($this->needs(['dependencies' => ['react-scripts' => '5.0.1']]));
        $this->assertFalse($this->needs(['devDependencies' => ['webpack' => '^5.90.0', 'react-scripts' => '4.0.3']]));
        $this->assertFalse($this->needs(['devDependencies' => ['laravel-mix' => '^6.0.49']]));
        $this->assertFalse($this->needs(['dependencies' => ['vite' => '^5.0.0']]));
        // Not a version: nothing to read from it.
        $this->assertFalse($this->needs(['dependencies' => ['react-scripts' => 'latest']]));
    }

    public function test_a_lockfile_resolving_webpack4_only_needs_it(): void
    {
        $this->assertTrue($this->needs(['dependencies' => ['x' => '1']], 'yarn.lock', "\nwebpack@4.42.0:\n  version \"4.42.0\"\n"));
        $this->assertTrue($this->needs(['dependencies' => ['x' => '1']], 'package-lock.json', json_encode([
            'packages' => ['node_modules/webpack' => ['version' => '4.47.0']],
        ], JSON_PRETTY_PRINT)));
        $this->assertTrue($this->needs(['dependencies' => ['x' => '1']], 'pnpm-lock.yaml', "packages:\n\n  webpack@4.47.0:\n    resolution: {}\n"));
    }

    public function test_a_lockfile_with_webpack5_too_does_not(): void
    {
        $this->assertFalse($this->needs(['dependencies' => ['x' => '1']], 'yarn.lock',
            "\nwebpack@4.42.0:\n  version \"4.42.0\"\n\nwebpack@^5.0.0:\n  version \"5.90.1\"\n"));
        // A nested webpack 4 under npm is not the project's build.
        $this->assertFalse($this->needs(['dependencies' => ['x' => '1']], 'package-lock.json', json_encode([
            'packages' => [
                'node_modules/webpack' => ['version' => '5.90.1'],
                'node_modules/foo/node_modules/webpack' => ['version' => '4.47.0'],
            ],
        ], JSON_PRETTY_PRINT)));
    }

    public function test_the_build_is_wrapped_only_when_needed(): void
    {
        file_put_contents($this->dir . '/package.json', json_encode(['dependencies' => ['react-scripts' => '3.4.3']]));

        $wrapped = NodeRuntime::withLegacyOpenssl('yarn run build', $this->dir);
        $this->assertSame(NodeRuntime::LEGACY_OPENSSL_ENV . ' && yarn run build', $wrapped);
        $this->assertSame('', NodeRuntime::withLegacyOpenssl('', $this->dir));
        $this->assertSame('yarn run build', NodeRuntime::withLegacyOpenssl('yarn run build', ''));

        file_put_contents($this->dir . '/package.json', json_encode(['dependencies' => ['react-scripts' => '5.0.1']]));
        $this->assertSame('yarn run build', NodeRuntime::withLegacyOpenssl('yarn run build', $this->dir));
    }

    /** Node 16 and older reject the flag in NODE_OPTIONS, so the shell checks the running Node. */
    public function test_the_shell_only_adds_the_flag_on_node_17_and_newer(): void
    {
        if (!is_executable('/bin/sh')) {
            $this->markTestSkipped('no /bin/sh');
        }
        $bin = $this->dir . '/bin';
        mkdir($bin);
        foreach (['v16.20.2' => '--max-old-space-size=1', 'v17.0.0' => '--max-old-space-size=1 --openssl-legacy-provider',
            'v22.23.3' => '--max-old-space-size=1 --openssl-legacy-provider', 'v24.1.0' => '--max-old-space-size=1 --openssl-legacy-provider'] as $version => $expected) {
            file_put_contents($bin . '/node', "#!/bin/sh\necho {$version}\n");
            chmod($bin . '/node', 0755);
            $out = shell_exec('PATH=' . escapeshellarg($bin) . ':$PATH NODE_OPTIONS=--max-old-space-size=1 /bin/sh -c '
                . escapeshellarg(NodeRuntime::LEGACY_OPENSSL_ENV . ' && echo "$NODE_OPTIONS"'));
            $this->assertSame($expected, trim((string) $out), $version);
        }
        $out = shell_exec('PATH=' . escapeshellarg($bin) . ':$PATH /bin/sh -c '
            . escapeshellarg(NodeRuntime::LEGACY_OPENSSL_ENV . ' && echo "[$NODE_OPTIONS]"'));
        $this->assertSame('[--openssl-legacy-provider]', trim((string) $out));
        @unlink($bin . '/node');
        @rmdir($bin);
    }

    public function test_both_dockerfiles_wrap_the_build(): void
    {
        file_put_contents($this->dir . '/package.json', json_encode(['dependencies' => ['react-scripts' => '3.4.3']]));
        $recipe = new BuildRecipe(['build_command' => 'npm run build'], ['package.json' => true], $this->dir);

        $expected = 'RUN ' . NodeRuntime::LEGACY_OPENSSL_ENV . ' && npm run build';
        $this->assertStringContainsString($expected, (new NodeDockerfile($recipe))->render());
        $this->assertStringContainsString($expected, (new StaticSiteDockerfile($recipe))->render());
    }
}
