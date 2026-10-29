<?php

namespace Tests\Unit\System\Firewall;

use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\Ufw\UfwLogs;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** Lines as ufw and fail2ban wrote them on an engine host (Ubuntu 24.04). */
class UfwLogsTest extends TestCase
{
    private static function journal(): string
    {
        $remote = TestNetwork::public2(9);
        $engine = TestNetwork::private(25);
        $container = TestNetwork::container2(4);
        $mail = TestNetwork::public(7);
        $scanner = TestNetwork::public2(10);

        return <<<LOG
2026-10-01T16:16:20+00:00 engine-host kernel: [UFW BLOCK] IN=eth0 OUT= MAC=33:33:00:00:00:01:bc:24:11:17:e9:4c:86:dd SRC=fe80:0000:0000:0000:be24:11ff:fe17:e94c DST=ff02:0000:0000:0000:0000:0000:0000:0001 LEN=204 TC=0 HOPLIMIT=1 FLOWLBL=672353 PROTO=UDP SPT=5678 DPT=5678 LEN=164
2026-10-01T16:17:01+00:00 engine-host kernel: [UFW BLOCK] IN=eth0 OUT= MAC=bc:24:11:aa:bb:cc SRC={$remote} DST={$engine} LEN=60 TOS=0x00 PREC=0x00 TTL=52 ID=0 DF PROTO=TCP SPT=51234 DPT=8000 WINDOW=64240 RES=0x00 SYN URGP=0
2026-10-01T16:17:02+00:00 engine-host kernel: [UFW BLOCK] IN=eth0 OUT=br-1a2b3c4d5e6f MAC=bc:24:11:aa:bb:cc SRC={$remote} DST={$container} LEN=60 TTL=51 PROTO=TCP SPT=51240 DPT=2222 WINDOW=64240 SYN URGP=0
2026-10-01T16:17:03+00:00 engine-host kernel: [UFW BLOCK] IN= OUT=eth0 SRC={$engine} DST={$mail} LEN=60 PROTO=TCP SPT=40000 DPT=25 SYN URGP=0
2026-10-01T16:17:04+00:00 engine-host kernel: [UFW BLOCK] IN=eth0 OUT= SRC={$remote} DST={$engine} LEN=28 PROTO=ICMP TYPE=8 CODE=0 ID=1 SEQ=1
2026-10-01T16:09:20.375894+00:00 engine-host kernel: message repeated 90 times: [ [UFW BLOCK] IN=eth0 OUT= SRC={$scanner} DST={$engine} PROTO=TCP SPT=1 DPT=23 ]
2026-10-01T16:17:05+00:00 engine-host kernel: [UFW AUDIT] IN=eth0 OUT= SRC={$remote} DST={$engine} PROTO=TCP DPT=22
-- No entries --
not-a-time kernel: [UFW BLOCK] IN=eth0 OUT= SRC={$remote} DST={$engine} PROTO=TCP DPT=1
2026-10-01T16:17:06+00:00 engine-host kernel: [UFW BLOCK] IN=eth0 OUT= PROTO=TCP DPT=1
LOG;
    }

    private static function fail2ban(): string
    {
        $banned = TestNetwork::private(44);

        return <<<LOG
2026-10-01 13:12:24,569 fail2ban.actions        [763342]: NOTICE  [sshd] Ban {$banned}
2026-10-01 13:14:08,101 fail2ban.actions        [763342]: NOTICE  [sshd] Unban {$banned}
2026-10-01 13:15:00,000 fail2ban.actions        [763342]: NOTICE  [sshd] Restore Ban 2001:0db8:0000:0000:0000:0000:0000:0001
2026-10-01 13:16:00,000 fail2ban.filter         [763342]: INFO    [sshd] Found {$banned} - 2026-10-01 13:16:00
LOG;
    }

    public function test_blocked_connections_are_read_from_the_kernel_log(): void
    {
        $entries = array_map(fn (FirewallLogEntry $e): array => $e->toArray(), UfwLogs::parseKernel(self::journal()));

        $this->assertCount(6, $entries);
        // IPv6 as the kernel writes it, compressed.
        $this->assertSame(['fe80::be24:11ff:fe17:e94c', 'in', 'udp', '5678'], [$entries[0]['address'], $entries[0]['direction'], $entries[0]['protocol'], $entries[0]['port']]);
        $this->assertSame([TestNetwork::public2(9), 'in', 'tcp', '8000'], [$entries[1]['address'], $entries[1]['direction'], $entries[1]['protocol'], $entries[1]['port']]);
        // A published port, refused on its way to the container: still arriving.
        $this->assertSame([TestNetwork::public2(9), 'in', '2222'], [$entries[2]['address'], $entries[2]['direction'], $entries[2]['port']]);
        // Leaving the host: the address is where it was going.
        $this->assertSame([TestNetwork::public(7), 'out', '25'], [$entries[3]['address'], $entries[3]['direction'], $entries[3]['port']]);
        // ICMP has no port.
        $this->assertSame(['icmp', null], [$entries[4]['protocol'], $entries[4]['port']]);
        // rsyslog's "message repeated" line still names the connection.
        $this->assertSame([TestNetwork::public2(10), '23', '2026-10-01T16:09:20+00:00'], [$entries[5]['address'], $entries[5]['port'], $entries[5]['time']]);
        foreach ($entries as $entry) {
            $this->assertSame('blocked', $entry['type']);
            $this->assertNull($entry['jail']);
        }
    }

    public function test_bans_and_releases_are_read_from_fail2bans_log(): void
    {
        $entries = array_map(fn (FirewallLogEntry $e): array => $e->toArray(), UfwLogs::parseFail2ban(self::fail2ban(), '+02:00'));

        $this->assertSame([
            ['time' => '2026-10-01T13:12:24+02:00', 'type' => 'ban', 'address' => TestNetwork::private(44), 'direction' => null, 'protocol' => null, 'port' => null, 'jail' => 'sshd'],
            ['time' => '2026-10-01T13:14:08+02:00', 'type' => 'unban', 'address' => TestNetwork::private(44), 'direction' => null, 'protocol' => null, 'port' => null, 'jail' => 'sshd'],
            ['time' => '2026-10-01T13:15:00+02:00', 'type' => 'ban', 'address' => '2001:db8::1', 'direction' => null, 'protocol' => null, 'port' => null, 'jail' => 'sshd'],
        ], $entries);
    }

    public function test_a_line_with_a_time_that_does_not_parse_is_skipped(): void
    {
        $this->assertSame([], UfwLogs::parseFail2ban('2026-13-45 99:99:99,000 fail2ban.actions [1]: NOTICE [sshd] Ban ' . TestNetwork::private(1), '+00:00'));
    }
}
