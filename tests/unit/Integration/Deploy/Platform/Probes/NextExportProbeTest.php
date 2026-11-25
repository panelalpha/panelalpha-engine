<?php

namespace Tests\Integration\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\Probes\NextExportProbe;

/**
 * A static export is what the `build` the engine runs exports: haim-ai/tamhil exports only under GITHUB_PAGES=1, and
 * TheAlgorithms/website runs `next export` from a script its build never calls. Both are Next.js servers.
 */
class NextExportProbeTest extends ProbeTestCase
{
    private function evaluate(): bool|array
    {
        return (new NextExportProbe)->evaluate($this->context());
    }

    private function nextApp(array $scripts, string $config = 'module.exports = {};'): void
    {
        $this->writeJson('package.json', ['scripts' => $scripts, 'dependencies' => ['next' => '16.3.5']]);
        $this->write('next.config.ts', $config);
    }

    public function test_an_unconditional_export_is_static(): void
    {
        $this->nextApp(['build' => 'next build'], 'export default { output: "export" };');

        $this->assertSame(['workspace_relative' => ''], $this->evaluate());
        $this->assertSame('nextjs-export', DetectProjectStrategy::detect($this->dir)['platform']);
    }

    public function test_an_export_under_a_condition_is_a_server(): void
    {
        $this->nextApp(
            ['build' => 'next build', 'build:pages' => 'cross-env GITHUB_PAGES=1 next build', 'start' => 'next start'],
            "const pages = process.env.GITHUB_PAGES === \"1\";\n"
            .'export default { ...(pages ? { output: "export" as const, basePath: "/tamhil" } : {}) };'
        );

        $this->assertFalse($this->evaluate());
        $this->assertSame('nextjs', DetectProjectStrategy::detect($this->dir)['platform']);
    }

    public function test_next_export_outside_the_build_is_a_server(): void
    {
        $this->nextApp([
            'build' => 'yarn fetch-algorithms && yarn build:next',
            'build:next' => 'next build',
            'export' => 'yarn build && next export',
        ]);

        $this->assertFalse($this->evaluate());
    }

    public function test_next_export_run_by_the_build_is_static(): void
    {
        $this->nextApp(['build' => 'npm run compile', 'compile' => 'next build && next export']);

        $this->assertSame(['workspace_relative' => ''], $this->evaluate());
    }

    public function test_a_workspace_apps_own_export_is_static(): void
    {
        $this->writeJson('package.json', ['workspaces' => ['apps/*'], 'scripts' => ['build' => 'turbo build']]);
        $this->writeJson('apps/web/package.json', ['name' => 'web', 'scripts' => ['build' => 'next build'], 'dependencies' => ['next' => '15.0.0']]);
        $this->write('apps/web/next.config.mjs', 'export default { output: "export" };');

        $this->assertSame('apps/web', $this->evaluate()['workspace_relative'] ?? null);
    }

    public function test_an_example_config_is_not_the_config(): void
    {
        $this->nextApp(['build' => 'next build']);
        $this->write('next.config.example.js', 'module.exports = { output: "export" };');

        $this->assertFalse($this->evaluate());
    }

    public function test_each_nextjs_recipe_hands_the_others_build_over(): void
    {
        $export = PlatformRegistry::find('nextjs-export')?->handOver;
        $server = PlatformRegistry::find('nextjs')?->handOver;

        $this->assertSame(['.next/BUILD_ID', 'nextjs'], [$export?->marker, $export?->recipe]);
        $this->assertSame(['out/index.html', 'nextjs-export'], [$server?->marker, $server?->recipe]);
    }
}
