<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use PHPUnit\Framework\TestCase;

class ImageCatalogUnusableEntriesTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        if ($this->path !== null) {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_a_version_that_is_not_a_scalar_or_not_declared_resolves_to_nothing(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'catalog');
        file_put_contents($this->path, implode("\n", [
            'runtimes:',
            '  php:',
            '    versions:',
            '      - version: true',
            'extras:',
            '  - runtime: php',
            '    version: [8]',
            '  - runtime: php',
            "    version: '9.9'",
            '  - image: composer:2',
            '    runtime: php',
            '',
        ]));
        ImageCatalog::useConfig($this->path);

        $this->assertSame([[
            'id' => 'composer',
            'ref' => 'composer:2',
            'kind' => HostPrewarmPlan::KIND_PULL,
            'runtime' => 'php',
            'prewarm' => null,
            'why' => '',
        ]], ImageCatalog::entries());
    }
}
