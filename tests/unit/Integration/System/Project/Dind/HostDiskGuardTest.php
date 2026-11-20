<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\System\Project\Dind\HostDiskGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostDiskGuardFixtures;

/**
 * The pre-deploy host disk check: a nearly full host refuses the
 * deploy by name instead of failing it later on ENOSPC.
 */
class HostDiskGuardTest extends TestCase
{
    use HostDiskGuardFixtures;

    public function test_refuses_when_either_path_is_under_the_minimum(): void
    {
        $guard = new HostDiskGuard($this->host(['/var/lib/docker' => 50, '/home' => 2 * 1048576]), 3 * 1073741824);

        $refusal = $guard->refusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('50M free on /var/lib/docker', $refusal);
        $this->assertStringContainsString('below the 3G', $refusal);
        $this->assertStringContainsString('DEPLOY_HOST_MIN_FREE', $refusal);
    }

    public function test_the_refusal_is_not_rewritten_as_the_accounts_disk_being_full(): void
    {
        $refusal = (new HostDiskGuard($this->host(['/home' => 100]), 3 * 1073741824))->refusal();

        $this->assertNotNull($refusal);
        $this->assertNull(DeployFailureExplainer::match($refusal));
    }
}
