<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\TlsHandshake;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;
use Tests\Support\TlsHandshakeFixtures;

/**
 * A real handshake against `openssl s_server`, which serves one certificate by
 * default and another for one SNI name: the name sent decides what comes back.
 */
class TlsHandshakeTest extends TestCase
{
    use TlsHandshakeFixtures;

    public function test_an_ipv6_literal_is_bracketed(): void
    {
        $this->assertSame('[2001:db8::1]', TlsHandshake::endpoint('2001:db8::1'));
        $this->assertSame('[2001:db8::1]', TlsHandshake::endpoint('[2001:db8::1]'));
        $this->assertSame(TestNetwork::public(45), TlsHandshake::endpoint(TestNetwork::public(45)));
    }
}
