<?php

namespace Tests\Unit\System\Project\Dind;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * `Filesystem::filePutContents()` is `sudo cp` then `sudo chown`/`chmod`, and
 * all three follow a symlink. Almost everything the DinD code writes lands in
 * the account's home, where the tenant can put a symlink in its place, so a
 * write there goes through `writeFileReplacingPath()` instead. Only the files
 * below write into the engine's own account directory, which the tenant
 * cannot change.
 */
class TenantPathWritesReplaceTest extends TestCase
{
    private const ENGINE_DIR_WRITERS = [
        'System/Project/Dind/AccountMemory.php' => 1,
        'System/Project/Dind/Services/S6ServiceManager.php' => 1,
        'System/Project/Dind/Services/SupervisordServiceManager.php' => 1,
        'System/Project/Dind/TenantNetworkMove.php' => 1,
    ];

    public function test_no_dind_writer_copies_onto_a_path_the_tenant_controls(): void
    {
        $found = [];
        foreach ($this->dindSources() as $relative => $source) {
            $count = substr_count($source, '->filePutContents(');
            if ($count > 0) {
                $found[$relative] = $count;
            }
        }
        ksort($found);

        $this->assertSame(self::ENGINE_DIR_WRITERS, $found);
    }

    /** @return array<string, string> path under app/ => source */
    private function dindSources(): array
    {
        $app = base_path('app');
        $sources = ['System/Project/Dind.php' => (string) file_get_contents($app.'/System/Project/Dind.php')];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app.'/System/Project/Dind'));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen($app) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}
