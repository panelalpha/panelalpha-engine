<?php

namespace Tests\Integration\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\ViteWithoutEntryProbe;

/**
 * poxiao33/toSub2 keeps vite at its root for the page in web/, and starts as `node src/console-server.mjs`;
 * microsoft/haste has its Vite app in ui/. The Vite platform claimed both and `npx vite build` died on
 * `Could not resolve entry module "index.html"`.
 */
class ViteWithoutEntryProbeTest extends ProbeTestCase
{
    private function evaluate(): bool
    {
        return (new ViteWithoutEntryProbe())->evaluate($this->context());
    }

    public function test_a_node_server_with_vite_and_no_root_page_is_started_as_node(): void
    {
        $this->writeJson('package.json', [
            'scripts' => ['start' => 'node src/console-server.mjs'],
            'devDependencies' => ['vite' => '^7.0.0'],
        ]);
        $this->write('web/index.html', '<!doctype html>');

        $this->assertTrue($this->evaluate());
        $this->assertSame('node', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_a_plain_vite_build_with_its_page_elsewhere_has_no_entry(): void
    {
        $this->writeJson('package.json', [
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^4.0.0'],
        ]);
        $this->write('ui-react/index.html', '<!doctype html>');

        $this->assertTrue($this->evaluate());
    }

    public function test_a_vitest_only_config_names_no_entry(): void
    {
        $this->writeJson('package.json', ['devDependencies' => ['vite' => '^7.0.0', 'vitest' => '^4.0.0']]);
        $this->write('vite.config.ts', "export default defineConfig({ test: { environment: 'jsdom' } });\n");

        $this->assertTrue($this->evaluate());
    }

    public function test_a_root_page_is_the_entry(): void
    {
        $this->writeJson('package.json', ['scripts' => ['build' => 'vite build'], 'devDependencies' => ['vite' => '^7.0.0']]);
        $this->write('index.html', '<!doctype html>');

        $this->assertFalse($this->evaluate());
        $this->assertSame('vite', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    /** @return array<string, array{string}> */
    public static function configsPointingElsewhere(): array
    {
        return [
            'root' => ["export default { root: 'client' }"],
            'rollup input' => ["export default { build: { rollupOptions: { input: 'src/main.html' } } }"],
            'library' => ["export default { build: { lib: { entry: 'src/index.ts' } } }"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configsPointingElsewhere')]
    public function test_a_config_naming_another_entry_is_trusted(string $config): void
    {
        $this->writeJson('package.json', ['devDependencies' => ['vite' => '^7.0.0']]);
        $this->write('vite.config.js', $config);

        $this->assertFalse($this->evaluate());
    }

    public function test_a_build_script_of_its_own_is_trusted(): void
    {
        $this->writeJson('package.json', ['scripts' => ['build' => 'react-router build'], 'devDependencies' => ['vite' => '^7.0.0']]);
        $this->assertFalse($this->evaluate());

        $this->writeJson('package.json', ['scripts' => ['build' => 'vite build --config vite.harness.config.ts'], 'devDependencies' => ['vite' => '^5.0.0']]);
        $this->assertFalse($this->evaluate());
    }
}
