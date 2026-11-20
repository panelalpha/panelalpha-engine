<?php

namespace Tests\Integration\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\BundlerSpaProbe;
use PHPUnit\Framework\TestCase;

/**
 * Where a webpack build writes when its config has no `output` block, and when that block never closes.
 */
class BundlerSpaOutputDirTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-webpack-out-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_config_without_an_output_block_writes_to_the_default(): void
    {
        file_put_contents($this->dir.'/webpack.config.js', "module.exports = { entry: './src/index.js', mode: 'production' };\n");

        $this->assertSame('dist', BundlerSpaProbe::outputDir($this->dir));
    }

    public function test_an_unclosed_output_block_is_read_to_the_end(): void
    {
        file_put_contents($this->dir.'/webpack.config.js', "module.exports = {\n  output: {\n    path: path.resolve(__dirname, 'build')\n");

        $this->assertSame('build', BundlerSpaProbe::outputDir($this->dir));
    }
}
