<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use Tests\TestCase;

/**
 * An image the operator selected for prewarming is the host's, so deleting an account never removes it.
 */
class DindAccountCleanupPrewarmTest extends TestCase
{
    public function test_a_selected_prewarm_image_survives_an_accounts_removal(): void
    {
        $item = HostPrewarmPlan::available()[0];
        config(['deploy.prewarm_images' => $item['id']]);

        $this->assertSame([$item['ref']], array_column(HostPrewarmPlan::catalog(), 'ref'));
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage($item['ref']));
        $this->assertSame(['opensearchproject/opensearch:2'], DindAccountCleanup::hostSidecarRefsToRemove([$item['ref'], 'opensearchproject/opensearch:2']));
    }
}
