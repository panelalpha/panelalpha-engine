<?php

namespace Tests\Unit;

use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\FtpAccountUpdateRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * pure-ftpd reads a 0 max size as no limit, so an FTP account created without
 * a quota, or with quota 0, must be reported as unlimited. The old getQuota()
 * cast a missing value to 0 and the controller stored "limited to 0 MB".
 */
class FtpAccountQuotaTest extends TestCase
{
    public function test_a_create_without_a_quota_asks_for_unlimited(): void
    {
        $this->assertNull($this->store(['user' => 'ftp1', 'domain' => 'shop.test', 'password' => 'secret-pass'])->getQuota());
    }

    public function test_a_create_with_a_zero_quota_asks_for_unlimited(): void
    {
        $this->assertNull($this->store(['user' => 'ftp1', 'domain' => 'shop.test', 'password' => 'secret-pass', 'quota' => 0])->getQuota());
    }

    public function test_a_create_with_a_positive_quota_keeps_it(): void
    {
        $this->assertSame(512, $this->store([
            'user' => 'ftp1', 'domain' => 'shop.test', 'password' => 'secret-pass', 'quota' => 512,
        ])->getQuota());
    }

    public function test_an_update_with_a_zero_quota_asks_for_unlimited(): void
    {
        $this->assertNull($this->update(['unlimited_quota' => false, 'quota' => 0])->getQuota());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store(array $payload): FtpAccountStoreRequest
    {
        return FtpAccountStoreRequest::createFrom(Request::create('/ftp-accounts', 'POST', $payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update(array $payload): FtpAccountUpdateRequest
    {
        return FtpAccountUpdateRequest::createFrom(Request::create('/ftp-accounts/x', 'PUT', $payload));
    }
}
