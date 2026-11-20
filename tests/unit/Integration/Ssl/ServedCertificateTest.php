<?php

namespace Tests\Integration\Ssl;

use App\Lib\Ssl\ServedCertificate;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** Pins at() where nothing answers; the handshake itself needs a TLS server and is not covered. */
class ServedCertificateTest extends TestCase
{
    public function test_a_port_nothing_listens_on_is_no_certificate(): void
    {
        $server = stream_socket_server('tcp://'.TestNetwork::LOOPBACK.':0');
        $this->assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        fclose($server);

        $this->assertNull(ServedCertificate::at((string) $address));
        $this->assertNull(ServedCertificate::at('https://'.$address.'/path'));
    }
}
