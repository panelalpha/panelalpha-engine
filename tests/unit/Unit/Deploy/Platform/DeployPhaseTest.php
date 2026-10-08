<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformStage;
use PHPUnit\Framework\TestCase;

/**
 * Which lifecycle phase a boot belongs to.
 *
 * The container cannot work this out for itself: a marker file inside the
 * image is wiped by the next rebuild, and nothing else in there distinguishes
 * a first deploy from a redeploy. Only the engine knows, from the account's
 * deploy history, and it says so through PA_DEPLOY_PHASE.
 */
class DeployPhaseTest extends TestCase
{
    public function test_an_account_that_never_deployed_is_installing(): void
    {
        $this->assertSame(PlatformStage::INSTALL, PlatformStage::phaseFor(null));
        $this->assertSame(PlatformStage::INSTALL, PlatformStage::phaseFor('unknown'));
        $this->assertSame(PlatformStage::INSTALL, PlatformStage::phaseFor('failed'));
    }

    public function test_an_account_that_deployed_before_is_upgrading(): void
    {
        $this->assertSame(PlatformStage::UPGRADE, PlatformStage::phaseFor('success'));
    }

    /**
     * A partial deploy still created the schema. Re-running the install
     * commands over it would seed a database that already has rows.
     */
    public function test_a_partial_deploy_counts_as_already_deployed(): void
    {
        $this->assertSame(PlatformStage::UPGRADE, PlatformStage::phaseFor('partial'));
    }

    /** A redeploy that failed does not undo the install before it. */
    public function test_a_failed_redeploy_of_a_deployed_account_is_still_upgrading(): void
    {
        $this->assertSame(PlatformStage::UPGRADE, PlatformStage::phaseFor('failed', true));
    }

    public function test_the_env_var_name_is_the_one_the_entrypoint_reads(): void
    {
        $this->assertSame('PA_DEPLOY_PHASE', PlatformStage::PHASE_ENV);
    }
}
