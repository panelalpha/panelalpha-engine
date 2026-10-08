<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\ViteNotTheBuildProbe;

/**
 * fluxer keeps `vite` in its root devDependencies for vitest and runs
 * `cargo run -p fluxer-dev -- build` as its root build. The Vite platform
 * claimed it and the host compile died on `sh: 1: cargo: not found`.
 */
class ViteNotTheBuildProbeTest extends ProbeTestCase
{
    private function evaluate(): bool
    {
        return (new ViteNotTheBuildProbe())->evaluate($this->context());
    }

    public function test_a_workspace_root_building_with_cargo_is_not_vite(): void
    {
        $this->writeJson('package.json', [
            'scripts' => ['build' => 'cargo run -p fluxer-dev -- build'],
            'devDependencies' => ['vite' => 'catalog:', '@vitest/coverage-v8' => 'catalog:'],
        ]);
        $this->write('pnpm-workspace.yaml', "packages:\n  - fluxer_app\n");
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $this->assertTrue($this->evaluate());
        $this->assertNotSame('vite', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_recursive_and_lerna_builds_are_not_vite(): void
    {
        $this->writeJson('package.json', [
            'workspaces' => ['packages/*'],
            'scripts' => ['build' => 'lerna run build', 'build:all' => 'pnpm -r build'],
            'devDependencies' => ['vite' => '^8.0.0'],
        ]);
        $this->assertTrue($this->evaluate());

        $this->writeJson('package.json', [
            'workspaces' => ['apps/*'],
            'scripts' => ['build' => 'pnpm -r build'],
            'devDependencies' => ['vite' => '^8.0.0', 'vitest' => '^4.0.0'],
        ]);
        $this->assertTrue($this->evaluate());
    }

    public function test_a_build_that_reaches_vite_through_a_script_is_vite(): void
    {
        $this->writeJson('package.json', [
            'workspaces' => ['packages/*'],
            'scripts' => ['build' => 'tsc -b && npm run build:web', 'build:web' => 'vite build --outDir dist'],
            'devDependencies' => ['vite' => '^5.0.0'],
        ]);

        $this->assertFalse($this->evaluate());
    }

    public function test_a_root_vite_config_keeps_the_claim(): void
    {
        $this->writeJson('package.json', [
            'workspaces' => ['packages/*'],
            'scripts' => ['build' => 'turbo run build'],
            'devDependencies' => ['vite' => '^5.0.0'],
        ]);
        $this->write('vite.config.ts', 'export default {}');

        $this->assertFalse($this->evaluate());
    }

    /** An ordinary single-package Vite SPA is not touched, whatever its build says. */
    public function test_a_single_package_project_keeps_the_claim(): void
    {
        $this->writeJson('package.json', [
            'scripts' => ['build' => 'tsc && node scripts/build.mjs'],
            'devDependencies' => ['vite' => '^5.0.0'],
        ]);
        $this->write('index.html', '<!doctype html>');

        $this->assertFalse($this->evaluate());
        $this->assertSame('vite', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_no_build_script_keeps_the_claim(): void
    {
        $this->writeJson('package.json', [
            'workspaces' => ['packages/*'],
            'devDependencies' => ['vite' => '^5.0.0'],
        ]);

        $this->assertFalse($this->evaluate());
    }
}
