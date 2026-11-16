<?php

namespace Tests\Integration\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\ViteOutputDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The vite recipe served `dist` whatever the config said.
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

    public function test_the_config_the_build_script_names_is_read_from_its_own_directory(): void
    {
        // chuspeeism/dashi-taskboard, trimmed.
        $this->file('web/vite.config.ts', <<<'JS'
            export default defineConfig({
              root: fileURLToPath(new URL(".", import.meta.url)),
              build: { outDir: fileURLToPath(new URL("../dist/web", import.meta.url)), emptyOutDir: true },
            });
            JS);
        $this->scripts(['build' => 'vite build --config web/vite.config.ts && node scripts/codex-injector.mjs']);

        $this->assertSame('dist/web', ViteOutputDir::outputDir($this->dir));
    }

    public function test_a_config_named_by_a_script_the_build_runs_is_read(): void
    {
        // SanMuzZzZz/LuaN1aoAgent, trimmed.
        $this->file('web/vite.config.ts', 'export default defineConfig({ root: __dirname, build: { outDir: "dist" } });');
        $this->scripts([
            'build' => 'npm run build:server && npm run build:web',
            'build:server' => 'tsc -p tsconfig.json',
            'build:web' => 'tsc -p web/tsconfig.json && vite build --config web/vite.config.ts',
        ]);

        $this->assertSame('web/dist', ViteOutputDir::outputDir($this->dir));
    }

    public function test_a_name_holding_the_configs_directory_anchors_its_paths(): void
    {
        // wesbos/3d-model-anything, trimmed.
        $this->file('apps/room-viewer/vite.config.ts', <<<'JS'
            const app = import.meta.dirname;
            export default defineConfig({
              root: app,
              build: { outDir: resolve(app, 'dist'), rollupOptions: { input: { main: resolve(app, 'index.html') } } },
            });
            JS);
        $this->scripts(['build' => 'tsc && vite build -c apps/room-viewer/vite.config.ts']);

        $this->assertSame('apps/room-viewer/dist', ViteOutputDir::outputDir($this->dir));
    }

    public function test_a_root_without_out_dir_holds_the_default(): void
    {
        // betaflight/betaflight-configurator, trimmed: Vitest's own root is not Vite's.
        file_put_contents($this->dir.'/vite.config.ts', <<<'JS'
            export default defineConfig({
                build: { rollupOptions: { input: { main: resolve(import.meta.dirname, "src/index.html") } } },
                test: { environment: "jsdom", root: "." },
                root: path.resolve(import.meta.dirname, "src"),
            });
            JS);

        $this->assertSame('src/dist', ViteOutputDir::outputDir($this->dir));
        $this->assertSame('src/www', ViteOutputDir::outputDir($this->dir, 'www'));
    }

    public function test_a_config_outside_the_project_is_not_read(): void
    {
        file_put_contents($this->dir.'/vite.config.ts', "export default { build: { outDir: 'root-out' } }");
        $this->scripts(['build' => 'vite build --config ../elsewhere/vite.config.ts']);

        $this->assertSame('dist', ViteOutputDir::outputDir($this->dir));
    }

    /** @param  array<string, string>  $scripts */
    private function scripts(array $scripts): void
    {
        file_put_contents($this->dir.'/package.json', json_encode(['scripts' => $scripts]));
    }

    private function file(string $relative, string $contents): void
    {
        mkdir(dirname($this->dir.'/'.$relative), 0777, true);
        file_put_contents($this->dir.'/'.$relative, $contents);
    }

    public function test_no_config_is_the_default(): void
    {
        $this->assertSame('dist', ViteOutputDir::outputDir($this->dir));
    }
}
