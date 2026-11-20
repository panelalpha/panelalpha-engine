<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * How the catalogue file's shapes read: an empty file, a runtime that is no mapping, bare version strings, and the warm order.
 */
class ImageCatalogConfigShapesTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/pa-catalog-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        @unlink($this->path);
        parent::tearDown();
    }

    /** @param  list<string>  $lines */
    private function catalog(array $lines): void
    {
        file_put_contents($this->path, implode("\n", $lines)."\n");
        ImageCatalog::useConfig($this->path);
    }

    public function test_an_empty_file_names_nothing(): void
    {
        file_put_contents($this->path, '');
        ImageCatalog::useConfig($this->path);

        $this->assertSame([], ImageCatalog::entries());
        $this->assertNull(ImageCatalog::budget());
    }

    public function test_images_warmed_at_one_priority_are_ordered_by_ref(): void
    {
        $this->catalog([
            'extras:',
            '  - {image: redis:7, runtime: node, prewarm: 10}',
            '  - {image: alpine:3, runtime: node, prewarm: 10}',
            '  - {image: nginx:alpine, runtime: node, prewarm: 20}',
            '  - {image: busybox:1, runtime: node}',
        ]);

        $this->assertSame(['nginx:alpine', 'alpine:3', 'redis:7'], array_column(ImageCatalog::prewarmed(), 'ref'));
    }

    public function test_a_runtime_that_is_not_a_mapping_is_skipped_and_a_bare_version_read(): void
    {
        $version = RuntimeImageCatalog::versions('node')[0];
        $this->catalog([
            'runtimes:',
            '  php: 5',
            '  node:',
            '    versions:',
            "      - '{$version}'",
        ]);

        $entries = ImageCatalog::entries();

        $this->assertSame(['node'], array_values(array_unique(array_column($entries, 'runtime'))));
        $this->assertSame(['node:'.$version], array_column($entries, 'id'));
    }

    public function test_a_flush_reads_the_file_again(): void
    {
        $this->catalog(['extras:', '  - {image: redis:7, runtime: node}']);
        $this->assertSame(['redis:7'], ImageCatalog::all());

        file_put_contents($this->path, "extras:\n  - {image: alpine:3, runtime: node}\n");
        $this->assertSame(['redis:7'], ImageCatalog::all());

        ImageCatalog::flush();
        $this->assertSame(['alpine:3'], ImageCatalog::all());
    }
}
