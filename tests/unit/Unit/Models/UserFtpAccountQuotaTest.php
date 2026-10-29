<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

class UserFtpAccountQuotaTest extends TestCase
{
    public function test_an_unlimited_project_reads_back_as_unlimited(): void
    {
        $this->assertSame(-1, $this->user(['disk_space_limit' => -1])->getRealFtpAccountQuota(null));
    }

    public function test_an_account_quota_is_capped_at_the_project_limit(): void
    {
        $user = $this->user(['disk_space_limit' => 500]);

        $this->assertSame(500, $user->getRealFtpAccountQuota(null));
        $this->assertSame(500, $user->getRealFtpAccountQuota(900));
        $this->assertSame(200, $user->getRealFtpAccountQuota(200));
    }

    public function test_a_project_without_a_disk_limit_keeps_the_account_quota(): void
    {
        $user = $this->user([]);

        $this->assertSame(200, $user->getRealFtpAccountQuota(200));
        $this->assertNull($user->getRealFtpAccountQuota(null));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function user(array $details): User
    {
        $user = new User();
        $user->setDetails($details);

        return $user;
    }
}
