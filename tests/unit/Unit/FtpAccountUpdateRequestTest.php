<?php

namespace Tests\Unit;

use App\Http\Requests\FtpAccountUpdateRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * getQuota() always returns a concrete int or null and can't tell "the
 * caller didn't send quota/unlimited_quota" from "the caller explicitly
 * asked for a 0-byte quota" -- before this fix, the controller called
 * getQuota() unconditionally, so a password-only update silently zeroed
 * the account's quota on every call. quotaProvided() exists to let the
 * controller keep the existing quota when neither field was sent.
 */
class FtpAccountUpdateRequestTest extends TestCase
{
    public function test_neither_field_sent_is_not_provided(): void
    {
        $this->assertFalse($this->request(['password' => 'newpass123'])->quotaProvided());
    }

    public function test_quota_alone_counts_as_provided(): void
    {
        $this->assertTrue($this->request(['quota' => 500])->quotaProvided());
    }

    public function test_unlimited_quota_false_still_counts_as_provided(): void
    {
        $this->assertTrue($this->request(['unlimited_quota' => false])->quotaProvided());
    }

    private function request(array $payload): FtpAccountUpdateRequest
    {
        $base = Request::create('/ftp-accounts/x', 'PUT', $payload);

        return FtpAccountUpdateRequest::createFrom($base);
    }
}
