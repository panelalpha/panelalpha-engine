<?php

namespace Tests\Unit\System\Firewall;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\Ufw\UfwFirewall;
use App\System\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\HostCommand;
use Tests\Support\TestNetwork;

/** The commands UfwFirewall::logs() runs on the host, and what it makes of their answers. */
class UfwFirewallLogsTest extends TestCase
{
    private const JOURNAL = ['journalctl', '-k', '--no-pager', '-o', 'short-iso', '-n', '5000', '-g', 'UFW BLOCK'];
    private const UFW_LOG = ['tail', '-n', '5000', '/var/log/ufw.log'];
    private const DATE = ['date', '+%:z'];
    private const FAIL2BAN_LOG = ['tail', '-n', '5000', '/var/log/fail2ban.log'];

    private static function block(string $time, string $src, string $port = '23'): string
    {
        return "{$time} h kernel: [UFW BLOCK] IN=eth0 OUT= SRC={$src} DST=" . TestNetwork::private(1) . " PROTO=TCP DPT={$port}\n";
    }

    private static function fail2ban(string $time, string $verb, string $address, string $jail = 'sshd'): string
    {
        return "{$time},000 fail2ban.actions [1]: NOTICE  [{$jail}] {$verb} {$address}\n";
    }

    /** @param array<string, Process> $answers command line prefix => answer; anything else fails */
    private function host(array $answers): ProcessRunner
    {
        return new class ($answers) implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ran = [];

            /** @param array<string, Process> $answers */
            public function __construct(private array $answers)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = HostCommand::argv($cmd);
                $this->ran[] = (array) $cmd;
                foreach ($this->answers as $prefix => $answer) {
                    if (str_starts_with(implode(' ', (array) $cmd), $prefix)) {
                        return $answer;
                    }
                }

                return FakeProcess::failed('not answered');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::ok();
            }
        };
    }

    /** @param list<FirewallLogEntry> $entries */
    private static function rows(array $entries): array
    {
        return array_map(static fn (FirewallLogEntry $e): array => $e->toArray(), $entries);
    }

    public function test_every_type_reads_the_journal_then_the_fail2ban_log_in_the_hosts_time_zone(): void
    {
        $host = $this->host([
            'journalctl' => FakeProcess::ok(self::block('2026-10-01T12:00:00+00:00', TestNetwork::public2(9))),
            'date' => FakeProcess::ok("+02:00\n"),
            'tail -n 5000 /var/log/fail2ban.log' => FakeProcess::ok(
                self::fail2ban('2026-10-01 14:30:00', 'Ban', TestNetwork::public(7))
                . self::fail2ban('2026-10-01 14:40:00', 'Unban', TestNetwork::public(7), 'engine-api')
            ),
        ]);

        $entries = (new UfwFirewall($host))->logs();

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([self::JOURNAL, self::DATE, self::FAIL2BAN_LOG], $host->ran);
        $this->assertSame([
            ['time' => '2026-10-01T14:40:00+02:00', 'type' => 'unban', 'address' => TestNetwork::public(7), 'direction' => null, 'protocol' => null, 'port' => null, 'jail' => 'engine-api'],
            ['time' => '2026-10-01T14:30:00+02:00', 'type' => 'ban', 'address' => TestNetwork::public(7), 'direction' => null, 'protocol' => null, 'port' => null, 'jail' => 'sshd'],
            ['time' => '2026-10-01T12:00:00+00:00', 'type' => 'blocked', 'address' => TestNetwork::public2(9), 'direction' => 'in', 'protocol' => 'tcp', 'port' => '23', 'jail' => null],
        ], self::rows($entries));
    }

    public function test_a_journal_that_answers_nothing_falls_back_to_the_ufw_log_file(): void
    {
        $host = $this->host([
            'journalctl' => FakeProcess::ok(" \n"),
            'tail -n 5000 /var/log/ufw.log' => FakeProcess::ok(self::block('2026-10-01T12:00:00+00:00', TestNetwork::public2(9), '8000')),
        ]);

        $entries = (new UfwFirewall($host))->logs(type: FirewallLogEntry::BLOCKED);

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([self::JOURNAL, self::UFW_LOG], $host->ran);
        $this->assertSame(['8000'], array_map(static fn (FirewallLogEntry $e): ?string => $e->port, $entries));
    }

    public function test_no_readable_kernel_log_is_no_blocked_entry(): void
    {
        $host = $this->host([]);

        $this->assertSame([], (new UfwFirewall($host))->logs(type: FirewallLogEntry::BLOCKED));
        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([self::JOURNAL, self::UFW_LOG], $host->ran);
    }

    public function test_bans_alone_never_read_the_kernel_log_and_an_unknown_offset_is_utc(): void
    {
        $host = $this->host([
            'date' => FakeProcess::ok("\n"),
            'tail -n 5000 /var/log/fail2ban.log' => FakeProcess::ok(
                self::fail2ban('2026-10-01 14:30:00', 'Ban', TestNetwork::public(7))
                . self::fail2ban('2026-10-01 14:40:00', 'Unban', TestNetwork::public(7))
            ),
        ]);

        $entries = (new UfwFirewall($host))->logs(type: FirewallLogEntry::BAN);

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([self::DATE, self::FAIL2BAN_LOG], $host->ran);
        $this->assertSame(['2026-10-01T14:30:00+00:00'], array_column(self::rows($entries), 'time'));
    }

    public function test_an_unreadable_fail2ban_log_is_no_ban(): void
    {
        $host = $this->host([
            'journalctl' => FakeProcess::ok(self::block('2026-10-01T12:00:00+00:00', TestNetwork::public2(9))),
            'date' => FakeProcess::ok("+00:00\n"),
        ]);

        $this->assertSame([], (new UfwFirewall($host))->logs(type: FirewallLogEntry::UNBAN));
        $this->assertSame(['blocked'], array_column(self::rows((new UfwFirewall($host))->logs()), 'type'));
    }

    public function test_the_address_is_compared_normalized_and_the_newest_are_kept_up_to_the_limit(): void
    {
        $host = $this->host([
            'journalctl' => FakeProcess::ok(
                self::block('2026-10-01T12:00:00+00:00', '2001:0db8:0000:0000:0000:0000:0000:0001', '1')
                . self::block('2026-10-01T12:05:00+00:00', '2001:0db8:0000:0000:0000:0000:0000:0001', '2')
                . self::block('2026-10-01T12:05:00+00:00', TestNetwork::public2(9), '3')
            ),
            'date' => FakeProcess::ok("+00:00\n"),
            'tail -n 5000 /var/log/fail2ban.log' => FakeProcess::ok(self::fail2ban('2026-10-01 12:05:00', 'Ban', '2001:db8::1')),
        ]);
        $firewall = new UfwFirewall($host);

        $this->assertSame(['2', 'ban', '1'], array_map(static fn (FirewallLogEntry $e): string => $e->port ?? $e->type, $firewall->logs(address: '2001:DB8:0::1')));
        $this->assertSame(['2', '3'], array_map(static fn (FirewallLogEntry $e): string => $e->port ?? $e->type, $firewall->logs(limit: 2)));
        $this->assertSame([], $firewall->logs(limit: 0));
    }
}
