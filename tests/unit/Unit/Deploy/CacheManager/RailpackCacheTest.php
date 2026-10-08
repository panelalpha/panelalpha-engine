<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\RailpackCache;
use PHPUnit\Framework\TestCase;

class RailpackCacheTest extends TestCase
{
    /**
     * The builder and runtime tags move with Railpack's release calendar, so
     * they are read from the plan rather than kept anywhere. Only the frontend
     * is named by the engine, because it goes in as a build-arg and the plan
     * never mentions it.
     */
    public function test_preload_images_are_the_frontend_plus_whatever_the_plan_names(): void
    {
        $plan = json_encode([
            'deploy' => ['base' => ['image' => 'ghcr.io/railwayapp/railpack-runtime:mise-2026.8.16']],
            'steps' => [
                'packages:mise' => [
                    'inputs' => [['image' => 'ghcr.io/railwayapp/railpack-builder:mise-2026.8.16']],
                ],
            ],
        ]);

        $this->assertSame(
            [
                RailpackCache::FRONTEND_IMAGE,
                'ghcr.io/railwayapp/railpack-runtime:mise-2026.8.16',
                'ghcr.io/railwayapp/railpack-builder:mise-2026.8.16',
            ],
            RailpackCache::preloadImages($plan)
        );
    }

    /**
     * An unreadable plan still has to leave the build something to start from,
     * and the frontend is the one image the engine can name without it.
     */
    public function test_an_unreadable_plan_still_yields_the_frontend(): void
    {
        $this->assertSame([RailpackCache::FRONTEND_IMAGE], RailpackCache::preloadImages('not json'));
    }
}
