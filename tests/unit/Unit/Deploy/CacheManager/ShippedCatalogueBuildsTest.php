<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Every image the shipped catalogue says we build is one prewarm can build and the publish script can push:
 * a package or version typo there would otherwise surface as a failed publish, or a variant no host ever has.
 */
class ShippedCatalogueBuildsTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeImageCatalog::useConfig(null);
        ImageCatalog::useConfig(null);
    }

    public function test_every_built_entry_renders_a_dockerfile(): void
    {
        $built = array_filter(HostPrewarmPlan::available(), static fn (array $i): bool => $i['kind'] === HostPrewarmPlan::KIND_BUILD);

        $this->assertNotEmpty($built);
        foreach ($built as $item) {
            $this->assertNotNull(
                BuiltImage::dockerfile($item['ref'], $item['extensions'], $item['packages']),
                "{$item['id']} names {$item['ref']} but nothing here can build it"
            );
        }
    }

    public function test_every_python_and_ruby_runtime_has_its_default_package_set(): void
    {
        $ids = array_column(HostPrewarmPlan::available(), 'id');
        foreach (['python' => ['3.10', '3.11', '3.12', '3.13', '3.14'], 'ruby' => ['3.2', '3.3', '3.4']] as $runtime => $versions) {
            foreach ($versions as $version) {
                $this->assertContains("{$runtime}:{$version}+libpq-dev", $ids);
            }
        }
    }
}
