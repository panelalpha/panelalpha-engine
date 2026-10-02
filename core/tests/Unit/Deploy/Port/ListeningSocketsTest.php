<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\ListeningSockets;
use PHPUnit\Framework\TestCase;

/**
 * What the container is actually listening on, read from /proc/net/tcp.
 *
 * The compose file says what the app was meant to serve; this says what it
 * does serve. They differ whenever an app ignores $PORT - anything built on
 * PocketBase, most Go binaries with a hardcoded default - and the result is
 * a deploy that reports success and answers 502.
 */
class ListeningSocketsTest extends TestCase
{
    private const HEADER =
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode';

    /**
     * One /proc/net/tcp row. `0A` is LISTEN; anything else is a live or
     * closing connection and must not be read as a served port.
     */
    private function row(int $index, string $addr, int $port, string $state = '0A'): string
    {
        return sprintf(
            '%4d: %s:%04X 00000000:0000 %s 00000000:00000000 00:00000000 00000000     0        0 %d 1',
            $index,
            $addr,
            $port,
            $state,
            1000 + $index
        );
    }

    private function procNet(string ...$rows): string
    {
        return self::HEADER . "\n" . implode("\n", $rows) . "\n";
    }

    public function test_a_listening_socket_is_read(): void
    {
        $sockets = ListeningSockets::fromProcNet($this->procNet($this->row(0, '00000000', 8080)));

        $this->assertSame([['addr' => '00000000', 'port' => 8080]], $sockets);
    }

    public function test_an_established_connection_is_not_a_listener(): void
    {
        // State 01 is ESTABLISHED. Reading it as a listener would point the
        // proxy at whatever ephemeral port an outbound request happened to use.
        $sockets = ListeningSockets::fromProcNet($this->procNet($this->row(0, '00000000', 8080, '01')));

        $this->assertSame([], $sockets);
    }

    public function test_the_header_line_is_not_a_socket(): void
    {
        $this->assertSame([], ListeningSockets::fromProcNet(self::HEADER . "\n"));
    }

    public function test_an_empty_file_yields_nothing(): void
    {
        $this->assertSame([], ListeningSockets::fromProcNet(''));
    }

    public function test_ipv4_and_ipv6_loopback_are_both_recognised(): void
    {
        $this->assertTrue(ListeningSockets::isLoopback('0100007F'));
        $this->assertTrue(ListeningSockets::isLoopback('0100007f'));
        $this->assertTrue(ListeningSockets::isLoopback('00000000000000000000000001000000'));
    }

    public function test_all_of_127_0_0_0_8_is_loopback(): void
    {
        // Docker's embedded DNS in every compose network: 127.0.0.11.
        $this->assertTrue(ListeningSockets::isLoopback('0B00007F'));
        $this->assertTrue(ListeningSockets::isLoopback('0100017F'));
        $this->assertFalse(ListeningSockets::isLoopback('7F00000A'), '10.0.0.127 is not loopback');
    }

    public function test_an_ipv4_mapped_loopback_is_loopback(): void
    {
        // A JVM's 127.0.0.1 listener, as it appears in /proc/net/tcp6.
        $this->assertTrue(ListeningSockets::isLoopback('0000000000000000FFFF00000100007F'));
        $this->assertTrue(ListeningSockets::isLoopback('0000000000000000ffff00000b00007f'));
        $this->assertFalse(ListeningSockets::isLoopback('0000000000000000FFFF00000200000A'), '::ffff:10.0.0.2');
        $this->assertFalse(ListeningSockets::isLoopback('00000000000000000000000000000000'), '[::]');
    }

    public function test_an_ipv4_mapped_loopback_listener_is_not_chosen(): void
    {
        // SignServer CE: WildFly's 127.0.0.1:8090 bound while 8081 was still booting.
        $sockets = [
            ['addr' => '0000000000000000FFFF00000100007F', 'port' => 8090],
            ['addr' => '0000000000000000FFFF00000100007F', 'port' => 9990],
        ];

        $this->assertNull(ListeningSockets::chooseAppPort($sockets, 8081));
    }

    public function test_the_wildcard_address_is_not_loopback(): void
    {
        $this->assertFalse(ListeningSockets::isLoopback('00000000'));
    }

    public function test_the_expected_port_being_served_needs_no_change(): void
    {
        $sockets = [['addr' => '00000000', 'port' => 8080]];

        $this->assertNull(ListeningSockets::chooseAppPort($sockets, 8080));
    }

    public function test_a_port_the_app_actually_bound_is_chosen(): void
    {
        // PocketBase's 8090 against a recipe that guessed 8080.
        $sockets = [['addr' => '00000000', 'port' => 8090]];

        $this->assertSame(8090, ListeningSockets::chooseAppPort($sockets, 8080));
    }

    public function test_the_most_web_like_port_wins_when_several_are_bound(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 9000],
            ['addr' => '00000000', 'port' => 3000],
            ['addr' => '00000000', 'port' => 4000],
        ];

        $this->assertSame(3000, ListeningSockets::chooseAppPort($sockets, 8080));
    }

    /** rapidbay's image runs a stock nginx on 80 beside its declared 5000. */
    public function test_a_port_the_image_declares_outranks_the_generic_preference(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 80],
            ['addr' => '00000000', 'port' => 5000],
        ];

        $this->assertSame(80, ListeningSockets::chooseAppPort($sockets, 6881));
        $this->assertSame(5000, ListeningSockets::chooseAppPort($sockets, 6881, [6881, 5000]));
    }

    /** engine#88: php-fpm on 9000 beside the real server on 8081; both stay candidates. */
    public function test_every_candidate_is_ranked_so_a_non_http_first_choice_can_be_passed_over(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 8081],
            ['addr' => '00000000', 'port' => 9000],
            ['addr' => '00000000', 'port' => 4369],
            ['addr' => '00000000', 'port' => 2222],
        ];

        $this->assertSame([9000, 8081], ListeningSockets::rankedAppPorts($sockets, 8080));
        $this->assertSame(9000, ListeningSockets::chooseAppPort($sockets, 8080));
        $this->assertSame([], ListeningSockets::rankedAppPorts([...$sockets, ['addr' => '00000000', 'port' => 8080]], 8080));
    }

    public function test_an_unrecognised_port_is_chosen_by_number(): void
    {
        $sockets = [
            ['addr' => '00000000', 'port' => 7777],
            ['addr' => '00000000', 'port' => 6666],
        ];

        $this->assertSame(6666, ListeningSockets::chooseAppPort($sockets, 8080));
    }

    public function test_a_loopback_only_listener_is_not_worth_pointing_at(): void
    {
        // Nothing outside the container can reach it, so republishing would
        // not help - better to keep waiting for the real server.
        $sockets = [['addr' => '0100007F', 'port' => 8090]];

        $this->assertNull(ListeningSockets::chooseAppPort($sockets, 8080));
    }

    public function test_a_sidecars_port_is_never_the_apps(): void
    {
        // The app has not bound anything yet; MySQL in the same network
        // namespace has. Forwarding to 3306 would serve the protocol banner.
        $sockets = [['addr' => '00000000', 'port' => 3306]];

        $this->assertNull(ListeningSockets::chooseAppPort($sockets, 8080));
    }

    public function test_an_ephemeral_port_is_never_the_apps(): void
    {
        $sockets = [['addr' => '00000000', 'port' => ListeningSockets::EPHEMERAL_PORT_MIN]];

        $this->assertNull(ListeningSockets::chooseAppPort($sockets, 8080));
    }

    public function test_nothing_listening_means_no_choice_to_make(): void
    {
        $this->assertNull(ListeningSockets::chooseAppPort([], 8080));
    }

    public function test_serves_reports_whether_the_published_port_answers(): void
    {
        $sockets = [['addr' => '00000000', 'port' => 8080]];

        $this->assertTrue(ListeningSockets::serves($sockets, 8080));
        $this->assertFalse(ListeningSockets::serves($sockets, 3000));
    }

    public function test_serves_does_not_count_a_loopback_listener(): void
    {
        // chooseAppPort() returns null both for "all good" and for "nothing
        // worth pointing at"; this is how the caller tells them apart, so a
        // loopback bind must not read as ready.
        $sockets = [['addr' => '0100007F', 'port' => 8080]];

        $this->assertFalse(ListeningSockets::serves($sockets, 8080));
    }
}
