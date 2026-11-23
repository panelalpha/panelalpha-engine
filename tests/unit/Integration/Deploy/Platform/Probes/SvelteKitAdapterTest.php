<?php

namespace Tests\Integration\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\SvelteKitAdapter;
use InvalidArgumentException;

/**
 * The adapter svelte.config imports decides what a SvelteKit build writes.
 *
 * golty lists adapter-static in devDependencies but configures adapter-auto:
 * it was served by nginx from an empty build/ (403). cogsend configures
 * adapter-cloudflare: it was started as `node build/index.js`, which that
 * adapter never writes, and restart-looped.
 */
class SvelteKitAdapterTest extends ProbeTestCase
{
    /**
     * @param  array<string, string>  $deps
     */
    private function project(string $adapterImport, array $deps): void
    {
        $this->writeJson('package.json', [
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['@sveltejs/kit' => '^2.0.0', 'svelte' => '^5.0.0'] + $deps,
        ]);
        $this->write('svelte.config.js', $adapterImport === ''
            ? "export default { kit: {} };\n"
            : "import adapter from '{$adapterImport}';\nexport default { kit: { adapter: adapter() } };\n");
    }

    private function refusal(): ?string
    {
        $decision = DetectProjectStrategy::detect($this->dir);
        try {
            DeployabilityCheck::assert($decision, $this->dir);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_the_configured_adapter_is_read(): void
    {
        $this->project('@sveltejs/adapter-cloudflare', []);
        $this->assertSame('cloudflare', SvelteKitAdapter::configured($this->context()));

        $this->write('svelte.config.js', "const adapter = require(\"@sveltejs/adapter-node\");\nmodule.exports = {};\n");
        $this->assertSame('node', SvelteKitAdapter::configured($this->context()));
    }

    /** A config choosing between two adapters by environment names no single one. */
    public function test_two_adapters_or_none_is_no_answer(): void
    {
        $this->write('svelte.config.js', "import a from '@sveltejs/adapter-node';\nimport b from '@sveltejs/adapter-static';\n");
        $this->assertNull(SvelteKitAdapter::configured($this->context()));

        $this->write('svelte.config.js', 'export default {};');
        $this->assertNull(SvelteKitAdapter::configured($this->context()));
    }

    public function test_golty_adapter_auto_with_adapter_static_installed_is_refused(): void
    {
        $this->project('@sveltejs/adapter-auto', ['@sveltejs/adapter-auto' => '^3.0.0', '@sveltejs/adapter-static' => '^3.0.0']);

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertNotSame('SvelteKit (static)', $decision['label']);
        $this->assertStringContainsString('adapter-auto', (string) $this->refusal());
    }

    public function test_cogsend_adapter_cloudflare_is_refused(): void
    {
        $this->project('@sveltejs/adapter-cloudflare', ['@sveltejs/adapter-cloudflare' => '^7.0.0']);

        $refusal = (string) $this->refusal();
        $this->assertStringContainsString('Cloudflare', $refusal);
        $this->assertStringContainsString('@sveltejs/adapter-node', $refusal);
    }

    public function test_adapter_static_is_the_static_recipe_even_with_adapter_node_installed(): void
    {
        $this->project('@sveltejs/adapter-static', ['@sveltejs/adapter-static' => '^3.0.0', '@sveltejs/adapter-node' => '^5.0.0']);

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame('SvelteKit (static)', $decision['label']);
        $this->assertNull($this->refusal());
    }

    public function test_adapter_node_is_the_node_recipe(): void
    {
        $this->project('@sveltejs/adapter-node', ['@sveltejs/adapter-node' => '^5.0.0', '@sveltejs/adapter-static' => '^3.0.0']);

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame('SvelteKit', $decision['label']);
        $this->assertNull($this->refusal());
    }

    /** No adapter named in the config: the dependencies decide, as before. */
    public function test_without_a_configured_adapter_the_dependencies_decide(): void
    {
        $this->project('', ['@sveltejs/adapter-static' => '^3.0.0']);
        $this->assertSame('SvelteKit (static)', DetectProjectStrategy::detect($this->dir)['label']);
        $this->assertNull($this->refusal());

        $this->project('', ['@sveltejs/adapter-static' => '^3.0.0', '@sveltejs/adapter-node' => '^5.0.0']);
        $this->assertSame('SvelteKit', DetectProjectStrategy::detect($this->dir)['label']);
    }

    /**
     * @param  array<string, string>  $deps
     */
    private function viteConfiguredProject(string $adapter, array $deps): void
    {
        $this->writeJson('package.json', [
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['@sveltejs/kit' => '^3.2.0', 'svelte' => '^5.57.0'] + $deps,
        ]);
        $this->write('vite.config.ts', "import adapter from '@sveltejs/adapter-{$adapter}';\n"
            ."import { sveltekit } from '@sveltejs/kit/vite';\n"
            ."export default { plugins: [sveltekit({ adapter: adapter() })] };\n");
    }

    /** cogsend dropped svelte.config and passes adapter-cloudflare to the Vite plugin; it restart-looped on build/index.js. */
    public function test_an_adapter_passed_to_the_vite_plugin_is_refused_by_name(): void
    {
        $this->viteConfiguredProject('cloudflare', ['@sveltejs/adapter-cloudflare' => '^8.0.0']);

        $this->assertSame('SvelteKit', DetectProjectStrategy::detect($this->dir)['label']);
        $this->assertSame(
            'vite.config.ts uses @sveltejs/adapter-cloudflare, which builds for Cloudflare only. Switch it to '
            .'@sveltejs/adapter-node (a Node server) or @sveltejs/adapter-static (a static site) to deploy it here.',
            $this->refusal()
        );
    }

    public function test_adapter_static_in_the_vite_config_is_the_static_recipe(): void
    {
        $this->viteConfiguredProject('static', ['@sveltejs/adapter-static' => '^3.0.0', '@sveltejs/adapter-node' => '^5.0.0']);

        $this->assertSame('SvelteKit (static)', DetectProjectStrategy::detect($this->dir)['label']);
        $this->assertNull($this->refusal());
    }

    /** svelte.config naming the adapter still decides over a vite.config that names another. */
    public function test_svelte_config_wins_over_the_vite_config(): void
    {
        $this->viteConfiguredProject('cloudflare', ['@sveltejs/adapter-node' => '^5.0.0']);
        $this->write('svelte.config.js', "import adapter from '@sveltejs/adapter-node';\nexport default { kit: { adapter: adapter() } };\n");

        $this->assertSame(['adapter' => 'node', 'file' => 'svelte.config.js'], SvelteKitAdapter::configuredIn($this->context()));
        $this->assertNull($this->refusal());
    }

    /** A svelte.config choosing between two adapters is no answer, whatever vite.config imports. */
    public function test_two_adapters_in_svelte_config_are_not_overruled_by_the_vite_config(): void
    {
        $this->viteConfiguredProject('cloudflare', []);
        $this->write('svelte.config.js', "import a from '@sveltejs/adapter-node';\nimport b from '@sveltejs/adapter-static';\n");

        $this->assertNull(SvelteKitAdapter::configured($this->context()));
    }
}
