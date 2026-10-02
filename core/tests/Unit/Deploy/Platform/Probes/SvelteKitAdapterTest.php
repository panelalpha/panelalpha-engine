<?php

namespace Tests\Unit\Deploy\Platform\Probes;

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
     * @param array<string, string> $deps
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
}
