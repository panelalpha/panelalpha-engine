<?php

namespace Tests\Integration;

use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\FtpAccountUpdateRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * getQuota() answers null when the request asks for an account with no quota
 * of its own; a missing quota and an explicit 0 are the same request.
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

    /** A form or a query string sends the flag as a word: `false` keeps the quota it names. */
    public function test_unlimited_quota_false_sent_as_a_word_keeps_the_quota(): void
    {
        $this->assertSame(500, $this->store([
            'user' => 'ftp1', 'domain' => 'shop.test', 'password' => 'secret-pass', 'unlimited_quota' => 'false', 'quota' => '500',
        ])->getQuota());
        $this->assertSame(500, $this->update(['unlimited_quota' => 'false', 'quota' => '500'])->getQuota());
        $this->assertNull($this->update(['unlimited_quota' => 'true', 'quota' => '500'])->getQuota());
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
