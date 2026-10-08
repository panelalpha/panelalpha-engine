<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use PHPUnit\Framework\TestCase;

class StandaloneNodeServeTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/standalone-node-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function test_prefers_a_nitro_output_server_when_both_exist(): void
    {
        $this->write('.output/server/index.mjs', 'export {}');
        $this->write('dist/server/server.js', 'export {}');

        $this->assertSame(
            '.output/server/index.mjs',
            StandaloneNodeServe::firstEntry($this->tmpDir, 'is_file')
        );
    }

    public function test_accepts_a_vite_ssr_server_when_nitro_output_is_absent(): void
    {
        $this->write('dist/server/server.js', 'export {}');

        $this->assertSame(
            'dist/server/server.js',
            StandaloneNodeServe::firstEntry($this->tmpDir, 'is_file')
        );
    }

    public function test_returns_null_when_the_build_wrote_nothing_runnable(): void
    {
        $this->write('dist/index.html', '<html></html>');

        $this->assertNull(StandaloneNodeServe::firstEntry($this->tmpDir, 'is_file'));
    }

    /** wemux: vite's root is apps/web, so Nitro wrote apps/web/.output and the root had none. */
    public function test_finds_the_nitro_output_a_workspace_app_wrote(): void
    {
        $this->write('apps/web/.output/server/index.mjs', 'export {}');
        $this->write('apps/web/.output/public/index.html', '<html></html>');
        $this->write('node_modules/pkg/.output/server/index.mjs', 'export {}');
        $this->write('packages/lib/dist/server/index.js', 'export {}');

        $this->assertNull(StandaloneNodeServe::firstEntry($this->tmpDir, 'is_file'));
        $this->assertSame(['apps/web/.output'], $this->nestedOutputDirs());
    }

    public function test_the_root_output_and_dirs_inside_it_are_not_nested_outputs(): void
    {
        $this->write('.output/server/index.mjs', 'export {}');
        $this->write('.output/x/.output/server/index.mjs', 'export {}');
        $this->write('apps/a/.output/server/index.js', 'export {}');
        $this->write('apps/b/.output/server/index.mjs', 'export {}');

        $this->assertSame(['apps/a/.output', 'apps/b/.output'], $this->nestedOutputDirs());
    }

    public function test_script_probes_known_entries_and_does_not_name_a_framework(): void
    {
        $script = StandaloneNodeServe::script();

        $this->assertStringContainsString('.output/server/index.mjs', $script);
        $this->assertStringContainsString('dist/server/server.js', $script);
        $this->assertStringContainsString('dist/client', $script);
        $this->assertStringNotContainsString('tanstack', strtolower($script));
        $this->assertStringNotContainsString('nuxt', strtolower($script));
        $this->assertStringNotContainsString('nitro()', $script);
    }

    public function test_start_command_matches_the_runtime_image(): void
    {
        $this->assertSame(
            'node ' . StandaloneNodeServe::FILENAME,
            StandaloneNodeServe::startCommand(Images::NODE_IMAGE)
        );
        $this->assertSame(
            'bun ' . StandaloneNodeServe::FILENAME,
            StandaloneNodeServe::startCommand(HostNodeBuild::BUN_IMAGE)
        );
    }

    /** @return list<string> the real `find` listing, sudo dropped, parsed */
    private function nestedOutputDirs(): array
    {
        $process = new \Symfony\Component\Process\Process(
            array_slice(StandaloneNodeServe::nestedEntriesArgv($this->tmpDir . '/'), 1)
        );
        $process->mustRun();
        return StandaloneNodeServe::nestedOutputDirs($this->tmpDir, $process->getOutput());
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
