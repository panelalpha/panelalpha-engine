<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\ViteOutputDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see ViteOutputDir::resolve()} on the call forms an `outDir` or `root` can take, and the CLI override.
 */
class ViteOutputDirCallsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function expressions(): array
    {
        return [
            'join is not anchored' => ["export default { root: 'app', build: { outDir: join('out', 'web') } }", null, 'app/out/web'],
            'resolve is anchored' => ["export default { root: 'app', build: { outDir: resolve('out', 'web') } }", null, 'out/web'],
            'join from __dirname is anchored' => ["export default { root: 'app', build: { outDir: path.join(__dirname, 'out') } }", null, 'out'],
            'process.cwd() first' => ["export default { build: { outDir: resolve(process.cwd(), 'out') } }", null, 'out'],
            'base alone is no path' => ['export default { build: { outDir: resolve(__dirname) } }', null, null],
            'a non-literal argument' => ['export default { build: { outDir: resolve(__dirname, notDeclared) } }', null, null],
            'an argument declared once' => ["const dir = 'gen';\nexport default { build: { outDir: resolve(__dirname, dir) } }", null, 'gen'],
            'a trailing comma' => ["export default { build: { outDir: 'out', } }", null, 'out'],
            'an unknown call' => ["export default { build: { outDir: compute('out') } }", null, null],
            'cli wins over config' => ["export default { build: { outDir: 'out' } }", 'cli-out', 'cli-out'],
            'cli resolves against root' => ["export default { root: './client', build: { outDir: 'out' } }", '../cli', 'cli'],
            'cli with no build block' => ['export default {}', 'cli', 'cli'],
            'non-literal config outDir is null even with cli' => ['export default { build: { outDir: process.env.OUT } }', 'cli', null],
            'non-literal root' => ["export default { root: someDir(), build: { outDir: 'out' } }", null, null],
            'absolute' => ["export default { build: { outDir: '/srv/out' } }", null, null],
            'root outside a build block only' => ["export default { build: { outDir: 'out', root: 'ignored' } }", null, 'out'],
        ];
    }

    #[DataProvider('expressions')]
    public function test_resolve(string $config, ?string $cli, ?string $expected): void
    {
        $this->assertSame($expected, ViteOutputDir::resolve($config, $cli));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function fromAConfigDirectory(): array
    {
        return [
            '__dirname is the config directory' => ["export default { build: { outDir: resolve(__dirname, 'out') } }", 'web/out'],
            'import.meta.dirname is too' => ["export default { build: { outDir: join(import.meta.dirname, 'out') } }", 'web/out'],
            'import.meta.url is too' => ["export default { build: { outDir: fileURLToPath(new URL('../out', import.meta.url)) } }", 'out'],
            'resolve() alone is the working directory' => ["export default { build: { outDir: resolve('out') } }", 'out'],
            'process.cwd() is the working directory' => ["export default { build: { outDir: resolve(process.cwd(), 'out') } }", 'out'],
            'a literal is under root' => ["export default { root: __dirname, build: { outDir: 'out' } }", 'web/out'],
            'a literal root is the working directory\'s' => ["export default { root: 'client', build: { outDir: 'out' } }", 'client/out'],
            'a name for the config directory' => ["const here = import.meta.dirname;\nexport default { root: here }", 'web/dist'],
            'a name for something else' => ["const here = compute();\nexport default { root: here }", null],
        ];
    }

    #[DataProvider('fromAConfigDirectory')]
    public function test_resolve_from_a_config_directory(string $config, ?string $expected): void
    {
        $this->assertSame($expected, ViteOutputDir::resolve($config, null, 'web', 'dist'));
    }

    public function test_without_a_default_a_missing_out_dir_is_unknown(): void
    {
        $this->assertNull(ViteOutputDir::resolve("export default { root: 'client' }"));
    }

    public function test_an_empty_project_directory_is_the_default(): void
    {
        $this->assertSame('dist', ViteOutputDir::outputDir('/', 'dist'));
    }
}
