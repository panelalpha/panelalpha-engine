<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * A temp directory to build one section of the inspection report over.
 *
 * {@see \Tests\Unit\Deploy\Inspect\AppInspectorTest} asserts that the whole
 * report describes the deploy. These go the other way: one section at a time,
 * driving the disagreements and edge cases the assembled report smooths over
 * — a port every source answers differently, an .env with more keys than the
 * response may carry, a datastore declared twice.
 */
abstract class ReportTestCase extends TestCase
{
    protected string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/app-report-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    protected function write(string $relative, string $contents = ''): void
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    protected function context(): ProjectContext
    {
        return ProjectContext::at($this->tmpDir);
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
