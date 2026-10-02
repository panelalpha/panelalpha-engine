<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use Tests\TestCase;

/** `DEPLOY_PREWARM_*` in `.env` against images.yaml's defaults. */
class HostPrewarmSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        parent::tearDown();
    }

    public function test_the_selection_comes_from_the_env(): void
    {
        config(['deploy.prewarm_images' => 'composer, php:8.3']);

        $this->assertSame(['composer', 'php:8.3'], HostPrewarmPlan::selected());
        // Warmed in the catalogue's priority order, not the order typed.
        $this->assertSame(['php:8.3', 'composer'], array_column(HostPrewarmPlan::catalog(), 'id'));
    }

    public function test_the_env_overrides_the_shipped_limits(): void
    {
        config(['deploy.prewarm_budget' => '2G', 'deploy.prewarm_reserve' => '20G']);

        $this->assertSame(2 * 1073741824, HostPrewarmPlan::budgetBytes());
        $this->assertSame(20 * 1073741824, HostPrewarmPlan::reserveBytes());

        config(['deploy.prewarm_budget' => 'none']);
        $this->assertNull(HostPrewarmPlan::budgetBytes());
    }

    public function test_empty_falls_back_to_images_yaml(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'images') . '.yaml';
        file_put_contents($path, "host:\n  reserve: 7G\n  budget: 3G\n");
        ImageCatalog::useConfig($path);
        config(['deploy.prewarm_budget' => '', 'deploy.prewarm_reserve' => '']);

        $this->assertSame(3 * 1073741824, HostPrewarmPlan::budgetBytes());
        $this->assertSame(7 * 1073741824, HostPrewarmPlan::reserveBytes());
        @unlink($path);
    }
}
