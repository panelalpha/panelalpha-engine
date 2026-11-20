<?php

namespace Tests\Support;

use App\Lib\Deploy\CacheManager\ImageCatalog;

trait ImageCatalogFixtures
{
    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        parent::tearDown();
    }
}
