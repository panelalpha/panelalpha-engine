<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\SslCheck;
use PHPUnit\Framework\TestCase;
use Tests\Support\SslCheckFixtures;
use Tests\Support\TestNetwork;

/**
 * The verdict comes from what a handshake with the domain returned, never from
 * the certificate on disk.
 */
class SslCheckTest extends TestCase
{
    use SslCheckFixtures;

    public function test_a_private_address_that_is_not_ours_is_never_contacted(): void
    {
        $result = $this->check([TestNetwork::METADATA], [], null);

        $this->assertSame(SslCheck::REFUSED_ADDRESS, $result['status']);
        $this->assertTrue($result['public']['refused']);
        $this->assertNotContains(TestNetwork::METADATA, array_column($this->dialled, 'address'));
    }

    public function test_nothing_answering_is_unreachable_with_the_reason(): void
    {
        $result = $this->check([TestNetwork::public(45)], [], null);

        $this->assertSame(SslCheck::UNREACHABLE, $result['status']);
        $this->assertFalse($result['public']['answered']);
        $this->assertSame('Connection refused', $result['public']['error']);
        $this->assertNull($result['engine']['serves_installed']);
    }

    public function test_no_certificate_means_no_expiry_or_signer(): void
    {
        $result = $this->check([], [], null);

        $this->assertNull($result['expires_at']);
        $this->assertNull($result['signer']);
        $this->assertNull($result['issuer']);
    }
}
