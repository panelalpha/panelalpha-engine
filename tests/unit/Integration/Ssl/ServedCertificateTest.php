<?php

namespace Tests\Integration\Ssl;

use App\Lib\Ssl\ServedCertificate;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** Pins at() where nothing answers; the handshake itself needs a TLS server and is not covered. */
class ServedCertificateTest extends TestCase
{
    public function test_an_address_with_no_host_is_no_certificate(): void
    {
        $this->assertNull(ServedCertificate::at(''));
        $this->assertNull(ServedCertificate::at('   '));
        $this->assertNull(ServedCertificate::at('https://'));
    }

    public function test_a_port_nothing_listens_on_is_no_certificate(): void
    {
        $server = stream_socket_server('tcp://'.TestNetwork::LOOPBACK.':0');
        $this->assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        fclose($server);

        $this->assertNull(ServedCertificate::at((string) $address));
        $this->assertNull(ServedCertificate::at('https://'.$address.'/path'));
    }

    public function test_covers_matches_names_and_one_wildcard_label(): void
    {
        $certificate = ['names' => ['engine.example.test', '*.sites.example.test']];

        $this->assertTrue(ServedCertificate::covers($certificate, 'ENGINE.example.test'));
        $this->assertTrue(ServedCertificate::covers($certificate, 'shop.sites.example.test'));
        $this->assertFalse(ServedCertificate::covers($certificate, 'a.shop.sites.example.test'));
        $this->assertFalse(ServedCertificate::covers($certificate, 'other.example.test'));
    }
}
