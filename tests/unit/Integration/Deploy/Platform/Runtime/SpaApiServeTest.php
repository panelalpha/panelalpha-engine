<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\SpaApiServe;
use PHPUnit\Framework\TestCase;

class SpaApiServeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-spa-api-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function context(array $files): ProjectContext
    {
        $listed = [];
        foreach ($files + ['package.json' => '{"scripts":{"build":"vite build"}}'] as $relative => $contents) {
            $path = $this->dir.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
            $listed[strtolower(basename($relative))] = true;
        }

        return ProjectContext::make($this->dir, $listed);
    }

    public function test_an_api_server_beside_a_vite_app_starts_behind_the_script(): void
    {
        $context = $this->context(['index.html' => '', 'vite.config.ts' => '', 'server/index.js' => "app.get('/api/health')"]);

        $this->assertTrue(SpaApiServe::applies($context, 'server/index.js'));
    }

    public function test_a_server_that_serves_the_build_starts_on_its_own(): void
    {
        foreach (['app.use(express.static(dist))', "res.sendFile(path.join(dist, 'index.html'))", "import serveStatic from 'serve-static'"] as $source) {
            $context = $this->context(['index.html' => '', 'vite.config.ts' => '', 'server/index.ts' => $source]);

            $this->assertFalse(SpaApiServe::applies($context, 'server/index.ts'), $source);
        }
    }

    public function test_without_a_vite_app_there_is_nothing_to_serve(): void
    {
        $this->assertFalse(SpaApiServe::applies($this->context(['server.js' => '']), 'server.js'));
        $this->assertFalse(SpaApiServe::applies($this->context(['vite.config.js' => '', 'server.js' => '']), 'server.js'));
    }

    public function test_the_dev_proxy_names_the_api_prefixes(): void
    {
        $context = $this->context(['vite.config.ts' => <<<'TS'
            export default defineConfig({
              plugins: [react()],
              resolve: { alias: { '@': path.resolve(__dirname, './src') } },
              server: { proxy: { '/api': 'http://localhost:3001', "/uploads/": { target: 'http://localhost:3001' }, '/ws': { ws: true } } },
            })
            TS]);

        $this->assertSame(['/api', '/uploads', '/ws'], SpaApiServe::apiPrefixes($context));
        $this->assertSame(['/api'], SpaApiServe::apiPrefixes($this->context(['vite.config.ts' => "export default { resolve: { alias: { '@': 'src' } } }"])));
    }

    public function test_the_script_carries_the_server_command_and_prefixes_as_literals(): void
    {
        $script = SpaApiServe::script('npx tsx server/index.ts', ['/api', "/it's"], 'dist');

        $this->assertStringContainsString('const SERVER_COMMAND = "npx tsx server/index.ts"', $script);
        $this->assertStringContainsString('const API_PREFIXES = ["/api","/it\'s"]', $script);
        $this->assertStringContainsString('const SERVER_PORT = 3001', $script);
        $this->assertStringContainsString('resolve(process.cwd(), "dist")', $script);
        $this->assertStringNotContainsString('{{', $script);
    }

    public function test_bun_runs_the_script_under_its_own_runtime(): void
    {
        $this->assertSame('bun panelalpha.spa-api.mjs', SpaApiServe::startCommand('bun'));
        $this->assertSame('node panelalpha.spa-api.mjs', SpaApiServe::startCommand('npm'));
    }

    public function test_a_decision_without_a_server_is_no_spa_api(): void
    {
        $this->assertNull(SpaApiServe::fromDecision([]));
        $this->assertNull(SpaApiServe::fromDecision(['spa_api' => ['server' => ' ']]));
        $this->assertSame(
            ['server' => 'node server.js', 'api_prefixes' => ['/api']],
            SpaApiServe::fromDecision(['spa_api' => ['server' => 'node server.js', 'api_prefixes' => ['no-slash', 3]]])
        );
    }
}
