<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\ViteOutputDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * engine#148: the vite recipe served `dist` whatever the config said.
 */
class ViteOutputDirTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vite-outdir-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function configs(): array
    {
        return [
            // LizardByte/Sunshine, trimmed.
            'sunshine: resolve() of a let holding a literal, root ignored' => [<<<'JS'
                let assetsSrcPath = 'src_assets/common/assets/web';
                let assetsDstPath = 'build/assets/web';
                if (process.env.SUNSHINE_ASSETS_DIR) {
                    assetsDstPath = resolve(fs.realpathSync(process.env.SUNSHINE_ASSETS_DIR), "assets/web");
                }
                export default defineConfig({
                    plugins: [vue()],
                    root: resolve(assetsSrcPath),
                    build: {
                        outDir: resolve(assetsDstPath),
                        emptyOutDir: true,
                    },
                })
                JS, 'build/assets/web'],
            'kresus: relative outDir resolves against root' => [
                "export default defineConfig({ root: './client', build: { outDir: '../build/client' } })",
                'build/client',
            ],
            'plain literal' => ["export default { build: { outDir: 'public/build' } }", 'public/build'],
            'resolve with __dirname' => [
                "export default { build: { outDir: path.resolve(__dirname, 'web', 'dist') } }",
                'web/dist',
            ],
            'fileURLToPath of a URL' => [
                "export default { build: { outDir: fileURLToPath(new URL('./out', import.meta.url)) } }",
                'out',
            ],
            'no outDir' => ["export default { build: { sourcemap: true } }", 'dist'],
            'no build block' => ['export default { plugins: [] }', 'dist'],
            'computed outDir keeps the default' => [
                'export default { build: { outDir: process.env.OUT } }',
                'dist',
            ],
            'escaping the project keeps the default' => [
                "export default { build: { outDir: '../../elsewhere' } }",
                'dist',
            ],
            'unknown root keeps the default' => [
                "export default { root: someDir(), build: { outDir: 'out' } }",
                'dist',
            ],
        ];
    }

    #[DataProvider('configs')]
    public function test_output_directory(string $config, string $expected): void
    {
        file_put_contents($this->dir . '/vite.config.ts', $config);

        $this->assertSame($expected, ViteOutputDir::outputDir($this->dir));
    }

    public function test_the_build_scripts_out_dir_wins(): void
    {
        file_put_contents($this->dir . '/vite.config.js', "export default { build: { outDir: 'a' } }");
        file_put_contents($this->dir . '/package.json', json_encode(['scripts' => ['build' => 'vite build --outDir=site && node post.js']]));

        $this->assertSame('site', ViteOutputDir::outputDir($this->dir));
    }

    public function test_vites_own_config_is_read_not_a_sibling(): void
    {
        // reveal.js ships vite.config.styles.ts beside vite.config.ts.
        file_put_contents($this->dir . '/vite.config.styles.ts', "export default { build: { outDir: 'styles' } }");
        file_put_contents($this->dir . '/vite.config.ts', 'export default {}');

        $this->assertSame('dist', ViteOutputDir::outputDir($this->dir));
    }

    public function test_no_config_is_the_default(): void
    {
        $this->assertSame('dist', ViteOutputDir::outputDir($this->dir));
    }
}
