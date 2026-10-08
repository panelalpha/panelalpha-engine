<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\RailpackPlan;
use PHPUnit\Framework\TestCase;

/**
 * Reading the images out of a railpack plan.
 *
 * This is what an account is preloaded with, so a ref this misses is one the
 * nested build pulls through the account's NAT instead — the 25-minute hang
 * preloading exists to prevent.
 */
class RailpackPlanTest extends TestCase
{
    /**
     * Railpack names images in two places today and has moved them between
     * releases, which is why this walks the document rather than reading two
     * fixed paths.
     */
    public function test_reads_images_from_both_shapes_a_plan_uses(): void
    {
        $plan = json_encode([
            'deploy' => ['base' => ['image' => 'ghcr.io/railwayapp/railpack-runtime:mise-2026.8.16']],
            'steps' => [
                'packages:mise' => [
                    'inputs' => [['image' => 'ghcr.io/railwayapp/railpack-builder:mise-2026.8.16']],
                ],
                'install' => ['inputs' => [['step' => 'packages:mise']]],
            ],
        ]);

        $this->assertSame(
            [
                'ghcr.io/railwayapp/railpack-runtime:mise-2026.8.16',
                'ghcr.io/railwayapp/railpack-builder:mise-2026.8.16',
            ],
            RailpackPlan::imageRefs($plan)
        );
    }

    public function test_a_plan_with_steps_as_a_list_reads_the_same(): void
    {
        $plan = json_encode([
            'steps' => [
                ['name' => 'packages:mise', 'inputs' => [['image' => 'ghcr.io/railwayapp/railpack-builder:x']]],
            ],
        ]);

        $this->assertSame(['ghcr.io/railwayapp/railpack-builder:x'], RailpackPlan::imageRefs($plan));
    }

    public function test_the_same_image_named_twice_is_returned_once(): void
    {
        $plan = json_encode([
            'deploy' => ['base' => ['image' => 'alpine:3.20']],
            'steps' => ['a' => ['inputs' => [['image' => 'alpine:3.20']]]],
        ]);

        $this->assertSame(['alpine:3.20'], RailpackPlan::imageRefs($plan));
    }

    /**
     * This feeds a pull, so anything docker would not take as a reference is
     * dropped rather than passed along.
     */
    public function test_drops_anything_that_is_not_a_usable_reference(): void
    {
        $plan = json_encode([
            'steps' => [
                'a' => ['inputs' => [['image' => '-rf']]],
                'b' => ['inputs' => [['image' => '']]],
                'c' => ['inputs' => [['image' => 'alpine:3.20']]],
            ],
        ]);

        $this->assertSame(['alpine:3.20'], RailpackPlan::imageRefs($plan));
    }

    public function test_an_unreadable_plan_names_nothing(): void
    {
        $this->assertSame([], RailpackPlan::imageRefs('not json'));
        $this->assertSame([], RailpackPlan::imageRefs('{}'));
        $this->assertSame([], RailpackPlan::imageRefs(''));
    }
}
