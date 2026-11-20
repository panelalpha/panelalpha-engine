<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

use App\Lib\Deploy\DetectProjectStrategy;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Strategy\FrameworkStrategy;
use Tests\TestCase;

/**
 * Pins what {@see FrameworkStrategy::apply()} writes for the host-compiled recipes: nginx static output,
 * a standalone Node server, a mounted Node project and a Python command runtime.
 */
class FrameworkStrategyApplyTest extends TestCase
{
    use StrategyHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_an_nginx_recipe_gets_its_config_a_lockfile_node_image_and_the_entrypoint(): void
    {
        $this->putFile('package.json', '{"name":"v","scripts":{"build":"vite build"},"devDependencies":{"vite":"^5"}}');
        $this->putFile('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $this->putFile('index.html', '<html></html>');
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['env'] = ['FROM_RECIPE' => '1'];

        $this->apply($decision, true);

        $this->assertMatchesGolden('framework-nginx');
    }

    public function test_a_standalone_node_server_gets_the_serve_script(): void
    {
        $this->putFile('package.json', '{"name":"n","scripts":{"build":"nuxt build","start":"node .output/server/index.mjs"},"dependencies":{"nuxt":"^3"}}');
        $this->putFile('nuxt.config.ts', 'export default {}');

        $this->apply(DetectProjectStrategy::detect($this->dir), false);

        $this->assertMatchesGolden('framework-standalone-node');
    }

    public function test_a_mounted_node_project_reads_its_image_from_the_app_root(): void
    {
        $this->putFile('web/package.json', '{"name":"x","engines":{"node":"20"},"dependencies":{"express":"^4"},"scripts":{"start":"node server.js"}}');
        $this->putFile('web/server.js', "require('express')().listen(3000)\n");
        $this->putFile('web/bun.lockb', 'x');
        $decision = DetectProjectStrategy::detect($this->dir.'/web');
        $decision['app_root'] = 'web';
        $decision['port_hint'] = null;

        $this->apply($decision, true);

        $this->assertMatchesGolden('framework-mounted-node');
    }

    public function test_a_python_runtime_swaps_in_the_shared_base(): void
    {
        $this->putFile('requirements.txt', "flask\ngunicorn\npsycopg2\n");
        $this->putFile('app.py', "from flask import Flask\napp = Flask(__name__)\n");

        $this->apply(DetectProjectStrategy::detect($this->dir), false, 'panelalpha/python:3.12-slim-pa1234');

        $this->assertMatchesGolden('framework-python-base');
    }

    public function test_a_python_runtime_keeps_the_stock_image_when_no_base_can_be_had(): void
    {
        $this->putFile('requirements.txt', "flask\ngunicorn\n");
        $this->putFile('app.py', "from flask import Flask\napp = Flask(__name__)\n");

        $this->apply(DetectProjectStrategy::detect($this->dir), true, null);

        $this->assertMatchesGolden('framework-python-stock');
    }

    /** @param  array<string, mixed>  $decision */
    private function apply(array $decision, bool $entrypoint, ?string $pythonBase = null): void
    {
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensurePythonBaseImage')->willReturnCallback(function (string $image, array $packages) use ($pythonBase): ?string {
            $this->record('ensurePythonBaseImage', [$image, $packages]);

            return $pythonBase;
        });

        (new FrameworkStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001], null, $entrypoint, $inner)))
            ->apply($decision, $this->dir, '1001:1001');
    }
}
