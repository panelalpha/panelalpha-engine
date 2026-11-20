<?php

namespace Tests\Integration\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\GoMetadata;
use App\Lib\Deploy\Platform\Probes\ComposerMetadataOnlyProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * Project files of shapes the readers pass over: a go.mod without a `go` line, a library whose `require` is no map,
 * and a folder named like a config file.
 */
class ProjectFileEdgesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-project-file-edges-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_go_mod_without_a_go_line_states_no_platform(): void
    {
        file_put_contents($this->dir.'/go.mod', "module github.com/acme/shop\n");

        $package = (new GoMetadata)->read(ProjectContext::at($this->dir));

        $this->assertNotNull($package);
        $this->assertSame('shop', $package->name);
        $this->assertSame([], $package->platform);
    }

    public function test_a_library_whose_require_is_not_a_map_is_not_metadata_only(): void
    {
        file_put_contents($this->dir.'/composer.json', '{"type":"library","require":"php"}');

        $this->assertFalse((new ComposerMetadataOnlyProbe)->evaluate(ProjectContext::at($this->dir)));
    }

    public function test_a_folder_named_like_a_config_is_not_its_contents(): void
    {
        mkdir($this->dir.'/vite.config.d');
        file_put_contents($this->dir.'/vite.config.ts', 'export default {}');

        $this->assertSame('export default {}', ProjectContext::firstConfigContents($this->dir, 'vite'));
    }
}
