<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * A temp project directory and a {@see ProjectContext} over it.
 *
 * Probes answer "is this project mine?" for a manifest whose files alone
 * cannot say, so every one of them is a function of a directory. Building the
 * context by hand keeps these tests about the probe rather than about which
 * platform wins.
 */
abstract class ProbeTestCase extends TestCase
{
    protected string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-probe-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        parent::tearDown();
    }

    protected function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, mixed> $json
     */
    protected function writeJson(string $relative, array $json): void
    {
        $this->write($relative, (string) json_encode($json, JSON_PRETTY_PRINT));
    }

    protected function context(): ProjectContext
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return ProjectContext::make($this->dir, $files);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
