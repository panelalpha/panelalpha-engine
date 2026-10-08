<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\TenantEgressGuard;
use Tests\TestCase;

/** The guard is on by default and DIND_EGRESS_GUARD=false turns it off. */
class TenantEgressGuardSwitchTest extends TestCase
{
    public function test_on_unless_switched_off(): void
    {
        config(['env.DIND_EGRESS_GUARD' => null]);
        $this->assertTrue(TenantEgressGuard::enabled());

        foreach (['false', '0', 'off', false] as $off) {
            config(['env.DIND_EGRESS_GUARD' => $off]);
            $this->assertFalse(TenantEgressGuard::enabled(), var_export($off, true));
        }

        config(['env.DIND_EGRESS_GUARD' => 'true']);
        $this->assertTrue(TenantEgressGuard::enabled());
    }
}
