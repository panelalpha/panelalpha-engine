<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\ViteOutputDir;
use PHPUnit\Framework\TestCase;

/**
 * A `build` block that never closes is read to the end of the config rather than dropped.
 */
class ViteOutputDirUnclosedTest extends TestCase
{
    public function test_an_unclosed_build_block_still_names_its_output_directory(): void
    {
        $this->assertSame('public/build', ViteOutputDir::resolve("export default defineConfig({\n  build: {\n    outDir: 'public/build',\n"));
    }
}
