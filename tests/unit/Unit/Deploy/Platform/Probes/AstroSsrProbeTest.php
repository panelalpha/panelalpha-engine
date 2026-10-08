<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\AstroSsrProbe;

/**
 * Does this Astro project need a Node server, or is it a folder of HTML?
 *
 * The two Astro manifests differ on exactly this, and getting it wrong is not
 * a broken deploy but a silently wrong one: a static site built as SSR runs a
 * Node process nobody needed, and an SSR app built as static serves its
 * routes as 404s.
 */
class AstroSsrProbeTest extends ProbeTestCase
{
    private function probe(): AstroSsrProbe
    {
        return new AstroSsrProbe();
    }

    public function test_an_explicit_static_output_settles_it(): void
    {
        // Whatever else the project has - even the node adapter installed but
        // unused - a config saying `static` is the author telling us directly.
        $this->write('astro.config.mjs', "export default defineConfig({ output: 'static' });");
        $this->writeJson('package.json', ['dependencies' => ['@astrojs/node' => '^8.0.0']]);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_the_node_adapter_means_a_server(): void
    {
        $this->write('astro.config.mjs', 'export default defineConfig({});');
        $this->writeJson('package.json', ['dependencies' => ['@astrojs/node' => '^8.0.0']]);

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    public function test_server_and_hybrid_output_both_mean_a_server(): void
    {
        foreach (['server', 'hybrid'] as $output) {
            $this->write('astro.config.mjs', "export default defineConfig({ output: '{$output}' });");

            $this->assertTrue($this->probe()->evaluate($this->context()), $output);
        }
    }

    public function test_a_start_script_that_only_previews_is_not_a_server(): void
    {
        // Templates commonly ship `astro dev` or `astro preview` as `start`.
        // Treating that as SSR builds a Node deployment for a site that only
        // ever needed nginx.
        foreach (['astro dev', 'astro preview', 'astro preview --host'] as $script) {
            $this->write('astro.config.mjs', 'export default defineConfig({});');
            $this->writeJson('package.json', ['scripts' => ['start' => $script]]);

            $this->assertFalse($this->probe()->evaluate($this->context()), $script);
        }
    }

    public function test_a_real_start_script_means_a_server(): void
    {
        $this->write('astro.config.mjs', 'export default defineConfig({});');
        $this->writeJson('package.json', ['scripts' => ['start' => 'node ./dist/server/entry.mjs']]);

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    public function test_a_bare_project_is_static(): void
    {
        // No config, no adapter, no start script: the default Astro build is a
        // directory of HTML, and nothing here says otherwise.
        $this->writeJson('package.json', ['dependencies' => ['astro' => '^4.0.0']]);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }
}
