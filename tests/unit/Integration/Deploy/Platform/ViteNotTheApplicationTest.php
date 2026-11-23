<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/**
 * Projects with Vite that are not a static Vite site: rfd-site is a React Router framework app with a server, froide
 * a Django and portainer a Go application whose front end Vite builds. Claimed by Vite, each failed on
 * `Static build finished but dist/index.html is missing`.
 */
class ViteNotTheApplicationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pa-vite-app-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->write('package-lock.json', '{"lockfileVersion":3,"packages":{}}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_a_react_router_framework_app_is_served_by_react_router(): void
    {
        $this->reactRouterApp("export default { ssr: true };\n");

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame(Strategies::REMIX, $decision['strategy']);
        $this->assertSame('React Router', $decision['label']);
        $this->assertStringContainsString('react-router-serve ./build/server/index.js', (string) $decision['start_command']);
    }

    public function test_a_react_router_app_without_a_config_is_still_react_router(): void
    {
        $this->reactRouterApp(null);

        $this->assertSame('React Router', DetectProjectStrategy::detect($this->dir)['label']);
    }

    /** `ssr: false` builds only build/client, a static site. */
    public function test_a_react_router_spa_stays_a_static_vite_site(): void
    {
        $this->reactRouterApp("export default { ssr: false };\n");

        $this->assertSame(Strategies::VITE, DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_a_django_application_building_its_assets_with_vite_is_django(): void
    {
        $this->viteAssets('vite build');
        $this->write('vite.config.ts', "export default { build: { outDir: 'build', rollupOptions: { input: { main: 'frontend/main.js' } } } };\n");
        $this->write('manage.py', "import os\nos.environ.setdefault(\"DJANGO_SETTINGS_MODULE\", \"froide.settings\")\n");
        $this->write('pyproject.toml', "[project]\nname = \"froide\"\ndependencies = [\"django\"]\n");

        $this->assertSame(Strategies::DJANGO, DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_a_go_application_with_vite_in_its_package_json_is_go(): void
    {
        $this->viteAssets('webpack');
        $this->write('go.mod', "module github.com/portainer/portainer\n\ngo 1.22\n");
        $this->write('main.go', "package main\n\nfunc main() {}\n");

        $this->assertSame(Strategies::GO, DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    /** A manage.py that is not Django's says nothing about the site. */
    public function test_a_vite_site_with_an_unrelated_manage_py_is_still_vite(): void
    {
        $this->viteAssets('vite build');
        $this->write('index.html', '<!doctype html><div id="app"></div>');
        $this->write('manage.py', "print('release helper')\n");

        $this->assertSame(Strategies::VITE, DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    private function reactRouterApp(?string $config): void
    {
        $this->write('package.json', (string) json_encode([
            'type' => 'module',
            'scripts' => ['build' => 'react-router build', 'dev' => 'react-router dev'],
            'dependencies' => ['@react-router/node' => '^7.0.0', '@react-router/serve' => '^7.0.0', 'react-router' => '^7.0.0'],
            'devDependencies' => ['@react-router/dev' => '^7.0.0', 'vite' => '^6.0.0'],
        ]));
        $this->write('vite.config.ts', "import { reactRouter } from '@react-router/dev/vite';\nexport default { plugins: [reactRouter()] };\n");
        if ($config !== null) {
            $this->write('react-router.config.ts', $config);
        }
    }

    private function viteAssets(string $build): void
    {
        $this->write('package.json', (string) json_encode([
            'scripts' => ['build' => $build],
            'devDependencies' => ['vite' => '^7.0.0'],
        ]));
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->dir.'/'.$relative, $contents);
    }
}
