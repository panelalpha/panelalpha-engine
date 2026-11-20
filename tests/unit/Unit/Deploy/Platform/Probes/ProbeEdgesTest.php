<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Metadata\MetadataRegistry;
use App\Lib\Deploy\Platform\Probes\BundlerSpaProbe;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use App\Lib\Deploy\Platform\Probes\SvelteKitAdapter;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * Probes asked about nothing: no SvelteKit config, an empty command, and registries reloaded after a flush.
 */
class ProbeEdgesTest extends TestCase
{
    private function nothing(): ProjectContext
    {
        return ProjectContext::make('/nonexistent/pa-probe-edges', []);
    }

    public function test_a_project_without_a_svelte_config_has_no_adapter(): void
    {
        $this->assertNull(SvelteKitAdapter::configured($this->nothing()));
    }

    public function test_an_empty_command_is_not_a_dev_server(): void
    {
        $this->assertFalse(BundlerSpaProbe::isDevServerOnly($this->nothing(), '   '));
        $this->assertFalse(BundlerSpaProbe::isDevServerOnly($this->nothing(), 'NODE_ENV=production '));
    }

    public function test_the_registries_load_the_same_classes_after_a_flush(): void
    {
        $probes = array_keys(ProbeRegistry::all());
        $readers = array_map(get_class(...), MetadataRegistry::all());

        ProbeRegistry::flush();
        MetadataRegistry::flush();

        $this->assertSame($probes, array_keys(ProbeRegistry::all()));
        $this->assertSame($readers, array_map(get_class(...), MetadataRegistry::all()));
        $this->assertNotSame([], $probes);
    }
}
