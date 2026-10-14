<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\DetectAppPort;
use PHPUnit\Framework\TestCase;

class AppPortDetectionTest extends TestCase
{
    private const PROC_HEADER =
        "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n";

    public function test_reads_listening_sockets_and_ignores_established_ones(): void
    {
        // 1F9A = 8090 listening on 0.0.0.0; 1F90 = 8080 but ESTABLISHED (01).
        $proc = self::PROC_HEADER
            . "   0: 00000000:1F9A 00000000:0000 0A 00000000:00000000 00:00000000  00000000  1000  0 123 1\n"
            . "   1: 0100007F:1F90 0100007F:C001 01 00000000:00000000 00:00000000  00000000  1000  0 124 1\n";

        $sockets = DetectAppPort::listeningSocketsFromProcNet($proc);

        $this->assertCount(1, $sockets);
        $this->assertSame(8090, $sockets[0]['port']);
    }

    /** beszel: PocketBase binds 8090, the Go recipe published 8080. */
    public function test_finds_the_real_port_when_the_app_ignores_the_recipe_guess(): void
    {
        $sockets = [['addr' => '00000000', 'port' => 8090]];

        $this->assertSame(8090, DetectAppPort::chooseAppPort($sockets, 8080));
    }

    public function test_changes_nothing_when_the_expected_port_is_served(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 8080],
            ['addr' => '00000000', 'port' => 9464],
        ];

        $this->assertNull(DetectAppPort::chooseAppPort($sockets, 8080));
    }

    public function test_ignores_loopback_only_listeners(): void
    {
        // Bound to 127.0.0.1 — unreachable from outside the container, so
        // remapping the published port would not fix anything.
        $sockets = [['addr' => '0100007F', 'port' => 8090]];

        $this->assertNull(DetectAppPort::chooseAppPort($sockets, 8080));
    }

    public function test_ignores_datastore_ports(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 5432],
            ['addr' => '00000000', 'port' => 6379],
        ];

        $this->assertNull(DetectAppPort::chooseAppPort($sockets, 8080));
    }

    public function test_prefers_a_conventional_web_port_when_several_are_open(): void
    {
        // A metrics port and an app port: pick the one meant for browsers.
        $sockets = [
            ['addr' => '00000000', 'port' => 9464],
            ['addr' => '00000000', 'port' => 3000],
        ];

        $this->assertSame(3000, DetectAppPort::chooseAppPort($sockets, 8080));
    }

    public function test_falls_back_to_the_lowest_unconventional_port(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 7777],
            ['addr' => '00000000', 'port' => 6060],
        ];

        $this->assertSame(6060, DetectAppPort::chooseAppPort($sockets, 8080));
    }

    public function test_reads_ipv6_listeners(): void
    {
        $proc = self::PROC_HEADER
            . "   0: 00000000000000000000000000000000:1F9A 00000000000000000000000000000000:0000 0A "
            . "00000000:00000000 00:00000000  00000000  1000  0 321 1\n";

        $sockets = DetectAppPort::listeningSocketsFromProcNet($proc);

        $this->assertSame(8090, $sockets[0]['port']);
        $this->assertFalse(DetectAppPort::isLoopbackAddress($sockets[0]['addr']));
    }

    public function test_ipv6_loopback_is_recognised(): void
    {
        $this->assertTrue(DetectAppPort::isLoopbackAddress('00000000000000000000000001000000'));
    }

    /**
     * we-promise/sure: Rails opened an ephemeral socket while running
     * db:prepare, minutes before Puma would have bound 3000. Taking it
     * produced a 3000:33931 mapping that was stale on the next restart.
     */
    public function test_ignores_an_ephemeral_port_opened_while_booting(): void
    {
        $sockets = [['addr' => '00000000', 'port' => 33931]];

        $this->assertNull(DetectAppPort::chooseAppPort($sockets, 3000));
    }

    public function test_ephemeral_boundary_is_respected(): void
    {
        $below = [['addr' => '00000000', 'port' => DetectAppPort::EPHEMERAL_PORT_MIN - 1]];
        $at = [['addr' => '00000000', 'port' => DetectAppPort::EPHEMERAL_PORT_MIN]];

        $this->assertSame(DetectAppPort::EPHEMERAL_PORT_MIN - 1, DetectAppPort::chooseAppPort($below, 3000));
        $this->assertNull(DetectAppPort::chooseAppPort($at, 3000));
    }

    public function test_serves_port_separates_healthy_from_not_ready_yet(): void
    {
        $healthy = [['addr' => '00000000', 'port' => 3000]];
        $loopbackOnly = [['addr' => '0100007F', 'port' => 3000]];

        $this->assertTrue(DetectAppPort::servesPort($healthy, 3000));
        $this->assertFalse(DetectAppPort::servesPort($loopbackOnly, 3000));
        $this->assertFalse(DetectAppPort::servesPort([], 3000));
    }

    public function test_nothing_listening_means_no_opinion(): void
    {
        $this->assertSame([], DetectAppPort::listeningSocketsFromProcNet(self::PROC_HEADER));
        $this->assertNull(DetectAppPort::chooseAppPort([], 8080));
    }
}
