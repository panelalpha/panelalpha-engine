<?php

namespace Tests\Support;

use App\Lib\Deploy\CacheManager\ImageCatalog;

trait HostPrewarmPlanFixtures
{
    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        parent::tearDown();
    }
}
