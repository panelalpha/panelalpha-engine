<?php

namespace Tests\Integration\System;

use App\System\EnginePaths;
use PHPUnit\Framework\TestCase;

class EnginePathsTest extends TestCase
{
    /** A template that predates the per-template `domain/` directory falls back. */
    public function test_the_domain_template_falls_back_when_the_template_has_none(): void
    {
        $root = sys_get_temp_dir() . '/pa-paths-' . bin2hex(random_bytes(4));
        mkdir($root . '/templates/user/withdomain/domain', 0777, true);
        mkdir($root . '/templates/user/plain', 0777, true);

        $p = new EnginePaths($root, '/homes');

        $this->assertSame(
            $root . '/templates/user/withdomain/domain',
            $p->projectDomainTemplateDir('withdomain')
        );
        $this->assertSame(
            $root . '/templates/user-home',
            $p->projectDomainTemplateDir('plain'),
            'a template with no domain/ directory falls back to the shared one'
        );

        $this->removeTree($root);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
