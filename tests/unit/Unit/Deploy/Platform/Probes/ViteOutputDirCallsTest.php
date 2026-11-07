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

    public function test_an_empty_project_directory_is_the_default(): void
    {
        $this->assertSame('dist', ViteOutputDir::outputDir('/', 'dist'));
    }
}
