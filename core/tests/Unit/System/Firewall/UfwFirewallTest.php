<?php

namespace Tests\Unit\System\Firewall;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\TrustedAddress;
use App\System\Firewall\Ufw\UfwFirewall;
use App\System\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;

class UfwFirewallTest extends TestCase
{
    private const OFFICE = '### tuple ### allow tcp 22 0.0.0.0/0 any 203.0.113.7 in comment=6f6666696365';
    private const BAN = '### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in';

    private string $lock;

    protected function setUp(): void
    {
        $this->lock = (string) tempnam(sys_get_temp_dir(), 'ufw-lock');
    }

    protected function tearDown(): void
    {
        @unlink($this->lock);
    }

    private function firewall(ProcessRunner $host, int $lockWait = 30): UfwFirewall
    {
        return new UfwFirewall($host, $this->lock, $lockWait);
    }

    /** Whether someone holds the ufw lock: a second handle cannot take it. */
    public static function lockHeld(string $lock): bool
    {
        $h = @fopen($lock, 'r');
        if ($h === false) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);

        return !$free;
    }

    /** A host whose ufw answers from $rules and records every command. */
    private function host(string $rules = self::OFFICE . "\n" . self::BAN, string $ufwOut = 'Rule added', int $ufwCode = 0): ProcessRunner
    {
        return new class ($rules, $ufwOut, $ufwCode, $this->lock) implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ran = [];
            /** @var list<bool> whether the ufw lock was held, per command in $ran */
            public array $held = [];

            public function __construct(public string $rules, public string $ufwOut, public int $ufwCode, private string $lock)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = (array) $cmd;
                $this->ran[] = $cmd;
                $this->held[] = UfwFirewallTest::lockHeld($this->lock);
                return match (true) {
                    $cmd === ['cat', '/etc/ufw/user.rules'] => FakeProcess::ok($this->rules),
                    $cmd === ['cat', '/etc/ufw/user6.rules'] => FakeProcess::failed('No such file'),
                    $cmd === ['ufw', 'version'] => FakeProcess::ok("ufw 0.36.2\nCopyright 2008-2023 Canonical Ltd.\n"),
                    $cmd === ['ufw', 'status', 'verbose'] => FakeProcess::ok("Status: active\nLogging: on (low)\nDefault: deny (incoming), allow (outgoing), deny (routed)\n"),
                    default => FakeProcess::of($this->ufwCode, $this->ufwOut),
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
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

    /** @return list<list<string>> */
    private function ufwCalls(ProcessRunner $host): array
    {
        /** @var object{ran: list<list<string>>} $host */
        return array_values(array_filter($host->ran, fn (array $c): bool => $c[0] === 'ufw'));
    }

    public function test_status_reads_state_version_and_defaults(): void
    {
        $status = ($this->firewall($this->host()))->status()->toArray();

        $this->assertSame([
            'provider' => 'ufw',
            'enabled' => true,
            'version' => '0.36.2',
            'default_incoming' => 'deny',
            'default_outgoing' => 'allow',
            'error' => null,
        ], $status);
    }

    public function test_a_deny_is_put_above_the_allows(): void
    {
        $host = $this->host();
        $rule = ($this->firewall($host))->addRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9/32']));

        $this->assertSame(['ufw', 'prepend', 'deny', 'in', 'from', '198.51.100.9', 'to', 'any'], $this->ufwCalls($host)[0]);
        $this->assertSame(['ufw', 'route', 'prepend', 'deny', 'from', '198.51.100.9', 'to', 'any'], $this->ufwCalls($host)[1], 'and above the published ports\' allows');
        $this->assertSame('198.51.100.9', $rule->source);
    }

    public function test_an_allow_is_appended(): void
    {
        $host = $this->host();
        ($this->firewall($host))->addRule(FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']));

        $this->assertSame('allow', $this->ufwCalls($host)[0][1]);
    }

    public function test_a_rule_for_published_ports_is_a_ufw_route_rule(): void
    {
        $routes = "### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in\n### tuple ### route:allow tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in";
        $host = $this->host(self::OFFICE . "\n" . $routes);
        $firewall = $this->firewall($host);
        $rule = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '8080', 'scope' => 'published']);

        $this->assertSame('published', $firewall->addRule($rule)->scope);
        $firewall->addRule($rule->with(['action' => 'deny', 'source' => '198.51.100.9', 'port' => null, 'protocol' => null]));
        $firewall->deleteRule($rule->id());

        $this->assertSame([
            ['ufw', 'route', 'allow', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '8080'],
            ['ufw', 'route', 'prepend', 'deny', 'from', '198.51.100.9', 'to', 'any'],
            ['ufw', 'route', 'delete', 'allow', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '8080'],
        ], array_slice($this->ufwCalls($host), 0, 3));
    }

    public function test_deleting_a_ban_on_published_ports_lifts_it_in_fail2ban_too(): void
    {
        $host = $this->host('### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd'));

        ($this->firewall($host))->deleteRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9', 'scope' => 'published'])->id());

        $this->assertSame(['ufw', 'route', 'delete', 'deny', 'from', '198.51.100.9', 'to', 'any'], $this->ufwCalls($host)[0]);
        /** @var object{ran: list<list<string>>} $host */
        $this->assertContains(['fail2ban-client', 'unban', '198.51.100.9'], $host->ran);
    }

    public function test_a_rule_is_deleted_by_what_it_matches_not_by_its_number(): void
    {
        $host = $this->host();
        $firewall = $this->firewall($host);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $deleted = $firewall->deleteRule($office->id());

        $this->assertSame('office', $deleted->comment);
        $this->assertSame(['ufw', 'delete', 'allow', 'in', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '22'], $this->ufwCalls($host)[0]);
    }

    public function test_deleting_a_fail2ban_ban_lifts_it_in_fail2ban_too(): void
    {
        $host = $this->host("### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in comment=" . bin2hex('by Fail2Ban after 5 attempts against sshd'));
        $firewall = $this->firewall($host);

        $firewall->deleteRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9'])->id());

        /** @var object{ran: list<list<string>>} $host */
        $this->assertContains(['fail2ban-client', 'unban', '198.51.100.9'], $host->ran);
    }

    public function test_deleting_any_other_rule_leaves_fail2ban_alone(): void
    {
        $host = $this->host();
        ($this->firewall($host))->deleteRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9'])->id());

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([], array_filter($host->ran, fn (array $c): bool => $c[0] === 'fail2ban-client'));
    }

    public function test_a_new_comment_is_a_delete_and_an_add(): void
    {
        $host = $this->host();
        $firewall = $this->firewall($host);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $firewall->updateRule($office->id(), $office->with(['comment' => 'home']));

        $calls = $this->ufwCalls($host);
        $this->assertSame('delete', $calls[0][1]);
        $this->assertSame(['ufw', 'allow', 'in', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '22', 'comment', 'home'], $calls[1]);
    }

    public function test_a_changed_match_adds_the_new_rule_before_removing_the_old(): void
    {
        $host = $this->host(self::OFFICE . "\n" . self::BAN . "\n### tuple ### allow tcp 2222 0.0.0.0/0 any 203.0.113.7 in");
        $firewall = $this->firewall($host);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $new = $firewall->updateRule($office->id(), $office->with(['port' => '2222']));

        $calls = $this->ufwCalls($host);
        $this->assertSame('allow', $calls[0][1]);
        $this->assertSame('delete', $calls[1][1]);
        $this->assertSame('2222', $new->port);
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->expectException(FirewallNotFound::class);

        ($this->firewall($this->host()))->deleteRule('000000000000');
    }

    public function test_ufws_refusal_is_reported_in_its_own_words(): void
    {
        $this->expectException(FirewallException::class);
        $this->expectExceptionMessage('Invalid position');

        ($this->firewall($this->host(ufwOut: "ERROR: Invalid position '1'", ufwCode: 1)))
            ->addRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']));
    }

    /** @param array<string, Process> $answers command => what the host returns, overriding host() */
    private function failing(array $answers, string $ufwOut = 'Rule added', int $ufwCode = 0): ProcessRunner
    {
        $base = $this->host(ufwOut: $ufwOut, ufwCode: $ufwCode);

        return new class ($base, $answers, $this->lock) implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ran = [];
            /** @var list<bool> */
            public array $held = [];

            /** @param array<string, Process> $answers */
            public function __construct(private ProcessRunner $base, private array $answers, private string $lock)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = (array) $cmd;
                $this->held[] = UfwFirewallTest::lockHeld($this->lock);
                foreach ($this->answers as $prefix => $answer) {
                    if (str_starts_with(implode(' ', (array) $cmd), $prefix)) {
                        return $answer;
                    }
                }

                return $this->base->runProcessOnHost($cmd, $env, $timeout);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
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

    public function test_status_without_ufw_says_so_instead_of_failing(): void
    {
        $status = ($this->firewall($this->failing(['ufw version' => FakeProcess::failed('', 127)])))->status();

        $this->assertNull($status->enabled);
        $this->assertSame('ufw is not installed', $status->error);
    }

    public function test_status_reports_ufws_own_error(): void
    {
        $status = ($this->firewall($this->failing(['ufw status' => FakeProcess::failed('ERROR: problem running iptables')])))->status();

        $this->assertNull($status->enabled);
        $this->assertSame('ERROR: problem running iptables', $status->error);
    }

    public function test_an_unreadable_rule_store_is_an_error_not_an_empty_list(): void
    {
        $this->expectException(FirewallException::class);
        $this->expectExceptionMessage('/etc/ufw/user.rules');

        ($this->firewall($this->failing(['cat /etc/ufw/user.rules' => FakeProcess::failed('Permission denied')])))->rules();
    }

    public function test_a_comment_change_that_ufw_refuses_puts_the_old_rule_back(): void
    {
        $host = $this->failing(['ufw allow in proto tcp from 203.0.113.7 to any port 22 comment bad' => FakeProcess::of(1, 'ERROR: Bad comment')]);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        try {
            ($this->firewall($host))->updateRule($office->id(), $office->with(['comment' => 'bad']));
            $this->fail('the refusal was swallowed');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Bad comment', $e->getMessage());
        }

        /** @var object{ran: list<list<string>>} $host */
        $this->assertContains(['ufw', 'allow', 'in', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '22', 'comment', 'office'], $host->ran);
    }

    private const BOTH = "### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in\n### tuple ### deny any any 203.0.113.7 any 0.0.0.0/0 out\n### tuple ### route:deny any any 0.0.0.0/0 any 203.0.113.7 in";

    public function test_a_deny_both_ways_is_added_and_deleted_as_its_host_and_route_rules(): void
    {
        $host = $this->host(self::BOTH);
        $firewall = $this->firewall($host);
        $rule = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7']);

        $added = $firewall->addRule($rule);
        $this->assertSame(['both', 'both'], [$added->direction, $added->scope]);
        $firewall->deleteRule($rule->id());

        $this->assertSame([
            ['ufw', 'prepend', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'],
            ['ufw', 'prepend', 'deny', 'out', 'from', 'any', 'to', '203.0.113.7'],
            ['ufw', 'route', 'prepend', 'deny', 'from', '203.0.113.7', 'to', 'any'],
            ['ufw', 'delete', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'],
            ['ufw', 'delete', 'deny', 'out', 'from', 'any', 'to', '203.0.113.7'],
            ['ufw', 'route', 'delete', 'deny', 'from', '203.0.113.7', 'to', 'any'],
        ], $this->ufwCalls($host));
    }

    public function test_a_deny_ufw_will_not_write_for_published_ports_is_taken_back_on_the_host(): void
    {
        $host = $this->failing(['ufw route prepend deny' => FakeProcess::of(1, 'ERROR: Bad source address')]);

        try {
            ($this->firewall($host))->addRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7']));
            $this->fail('a deny on the host alone was left in place');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Bad source address', $e->getMessage());
        }

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame(['ufw', 'delete', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'], end($host->ran));
    }

    public function test_when_the_second_half_is_refused_the_first_is_taken_back(): void
    {
        $host = $this->failing(['ufw prepend deny out' => FakeProcess::of(1, "ERROR: Bad destination address")]);

        try {
            ($this->firewall($host))->addRule(FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7', 'comment' => 'spam']));
            $this->fail('half a rule was left in place');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Bad destination address', $e->getMessage());
        }

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame(['ufw', 'delete', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'], end($host->ran));
    }

    public function test_logs_merge_blocks_and_bans_newest_first(): void
    {
        $host = $this->failing([
            'journalctl' => FakeProcess::ok(
                "2026-10-01T12:00:00+00:00 h kernel: [UFW BLOCK] IN=eth0 OUT= SRC=198.51.100.9 DST=10.0.0.1 PROTO=TCP DPT=23\n"
                . "2026-10-01T12:02:00+00:00 h kernel: [UFW BLOCK] IN=eth0 OUT= SRC=203.0.113.7 DST=10.0.0.1 PROTO=TCP DPT=8000\n"
            ),
            'date' => FakeProcess::ok("+00:00\n"),
            'tail -n 5000 /var/log/fail2ban.log' => FakeProcess::ok("2026-10-01 12:01:00,000 fail2ban.actions [1]: NOTICE  [sshd] Ban 198.51.100.9\n"),
        ]);
        $firewall = $this->firewall($host);

        $this->assertSame(['203.0.113.7', '198.51.100.9', '198.51.100.9'], array_map(fn ($e) => $e->address, $firewall->logs()));
        $this->assertSame(['ban'], array_map(fn ($e) => $e->type, $firewall->logs(type: 'ban')));
        $this->assertSame(['ban', 'blocked'], array_map(fn ($e) => $e->type, $firewall->logs(address: '198.51.100.9')));
        $this->assertCount(1, $firewall->logs(limit: 1));
    }

    public function test_without_the_journal_the_ufw_log_file_is_read(): void
    {
        $host = $this->failing([
            'journalctl' => FakeProcess::failed('Compiled without pattern matching support'),
            'tail -n 5000 /var/log/ufw.log' => FakeProcess::ok("2026-10-01T12:00:00.1+00:00 h kernel: [UFW BLOCK] IN=eth0 OUT= SRC=198.51.100.9 DST=10.0.0.1 PROTO=TCP DPT=23\n"),
        ]);

        $entries = ($this->firewall($host))->logs(type: 'blocked');

        $this->assertSame(['198.51.100.9'], array_map(fn ($e) => $e->address, $entries));
        /** @var object{ran: list<list<string>>} $host */
        $this->assertNotContains(['tail', '-n', '5000', '/var/log/fail2ban.log'], $host->ran, 'blocked only: fail2ban is not read');
    }

    private const TRUSTED_FILE = "198.51.100.7 # office\n2001:db8::/32\n";

    public function test_trusting_an_address_writes_the_list_applies_it_and_lifts_its_ban(): void
    {
        $host = $this->failing(['cat /etc/fail2ban/panelalpha-ignoreip' => FakeProcess::ok(self::TRUSTED_FILE)]);
        $firewall = $this->firewall($host);

        $this->assertSame(['198.51.100.7', '2001:db8::/32'], array_map(fn ($a) => $a->address, $firewall->trustedAddresses()));

        $firewall->trust(new TrustedAddress('203.0.113.7/32', 'monitoring'));

        /** @var object{ran: list<list<string>>} $host */
        $write = array_values(array_filter($host->ran, fn (array $c): bool => $c[0] === 'sh'))[0];
        $this->assertSame('/etc/fail2ban/panelalpha-ignoreip', $write[3]);
        $this->assertSame("198.51.100.7 # office\n2001:db8::/32\n203.0.113.7 # monitoring\n", $write[4]);
        $this->assertContains(['bash', '/opt/panelalpha/shared-hosting/scripts/firewall.sh', '--fail2ban'], $host->ran);
        $this->assertContains(['fail2ban-client', 'unban', '203.0.113.7'], $host->ran);
    }

    public function test_trusting_a_range_lifts_the_bans_inside_it_which_the_reload_keeps(): void
    {
        $host = $this->failing(['cat /etc/fail2ban/panelalpha-ignoreip' => FakeProcess::failed('No such file')]);

        ($this->firewall($host))->trust(new TrustedAddress('203.0.113.0/24'));

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([['fail2ban-client', 'unban', '203.0.113.0/24']], array_values(array_filter($host->ran, fn (array $c): bool => $c[0] === 'fail2ban-client')));
    }

    public function test_untrusting_removes_it_and_an_unknown_id_is_not_found(): void
    {
        $host = $this->failing(['cat /etc/fail2ban/panelalpha-ignoreip' => FakeProcess::ok(self::TRUSTED_FILE)]);
        $firewall = $this->firewall($host);

        $removed = $firewall->untrust((new TrustedAddress('198.51.100.7'))->id());

        $this->assertSame('office', $removed->comment);
        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame("2001:db8::/32\n", array_values(array_filter($host->ran, fn (array $c): bool => $c[0] === 'sh'))[0][4]);

        $this->expectException(FirewallNotFound::class);
        $firewall->untrust('000000000000');
    }

    public function test_a_list_fail2ban_does_not_take_is_an_error(): void
    {
        $this->expectException(FirewallException::class);
        $this->expectExceptionMessage('fail2ban did not start');

        ($this->firewall($this->failing([
            'cat /etc/fail2ban/panelalpha-ignoreip' => FakeProcess::ok(''),
            'bash /opt/panelalpha/shared-hosting/scripts/firewall.sh' => FakeProcess::failed('firewall: fail2ban did not start'),
        ])))->trust(new TrustedAddress('203.0.113.7'));
    }

    public function test_a_list_that_cannot_be_written_is_an_error(): void
    {
        $this->expectException(FirewallException::class);
        $this->expectExceptionMessage('Could not write /etc/fail2ban/panelalpha-ignoreip');

        ($this->firewall($this->failing([
            'cat /etc/fail2ban/panelalpha-ignoreip' => FakeProcess::ok(''),
            'sh -c' => FakeProcess::failed('Read-only file system'),
        ])))->trust(new TrustedAddress('203.0.113.7'));
    }

    public function test_switching_it_on_and_off_and_reloading(): void
    {
        $host = $this->host();
        $firewall = $this->firewall($host);
        $firewall->enable();
        $firewall->disable();
        $firewall->reload();

        $this->assertSame([['ufw', '--force', 'enable'], ['ufw', 'disable'], ['ufw', 'reload']], $this->ufwCalls($host));
    }

    /** @return list<array{list<string>, bool}> each ufw write and whether the lock was held for it */
    private function ufwWrites(ProcessRunner $host): array
    {
        /** @var object{ran: list<list<string>>, held: list<bool>} $host */
        $writes = [];
        foreach ($host->ran as $i => $cmd) {
            if ($cmd[0] === 'ufw' && !in_array($cmd[1] ?? '', ['version', 'status'], true)) {
                $writes[] = [$cmd, $host->held[$i]];
            }
        }

        return $writes;
    }

    public function test_every_ufw_write_holds_the_host_wide_lock(): void
    {
        $host = $this->host(self::OFFICE . "\n" . self::BOTH . "\n### tuple ### allow tcp 2222 0.0.0.0/0 any 203.0.113.7 in");
        $firewall = $this->firewall($host);
        $both = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7']);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $firewall->addRule($both);
        $firewall->deleteRule($both->id());
        $firewall->updateRule($office->id(), $office->with(['comment' => 'home']));
        $firewall->updateRule($office->id(), $office->with(['port' => '2222']));
        $firewall->enable();
        $firewall->disable();
        $firewall->reload();

        $writes = $this->ufwWrites($host);
        $this->assertCount(13, $writes);
        $this->assertSame([], array_values(array_filter($writes, fn (array $w): bool => !$w[1])), 'a ufw write ran without the lock');
        $this->assertFalse(self::lockHeld($this->lock), 'and it is released afterwards');
    }

    public function test_a_rule_is_written_and_read_back_under_one_hold_of_the_lock(): void
    {
        $host = $this->host(self::BOTH);

        $this->firewall($host)->addRule(FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7']));

        /** @var object{ran: list<list<string>>, held: list<bool>} $host */
        $this->assertSame(['ufw', 'ufw', 'ufw', 'cat', 'cat'], array_column($host->ran, 0));
        $this->assertSame([true, true, true, true, true], $host->held, 'all three halves and the read-back');
    }

    public function test_fail2ban_is_told_of_a_lifted_ban_only_once_the_lock_is_released(): void
    {
        // fail2ban's unban action waits for the same lock: asking while holding it would deadlock.
        $comment = bin2hex('by Fail2Ban after 5 attempts against sshd');
        $host = $this->host("### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in comment={$comment}");

        $this->firewall($host)->deleteRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9'])->id());

        /** @var object{ran: list<list<string>>, held: list<bool>} $host */
        $unban = array_search(['fail2ban-client', 'unban', '198.51.100.9'], $host->ran, true);
        $this->assertNotFalse($unban);
        $this->assertFalse($host->held[$unban]);
        $this->assertGreaterThan(array_search('ufw', array_column($host->ran, 0), true), $unban, 'after the rules are gone');
    }

    public function test_a_ban_given_a_new_comment_is_lifted_in_fail2ban_before_the_rule_is_re_added(): void
    {
        $ban = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']);
        $host = $this->host('### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd'));

        $this->firewall($host)->updateRule($ban->id(), $ban->with(['comment' => 'keep out']));

        /** @var object{ran: list<list<string>>, held: list<bool>} $host */
        $steps = [];
        foreach ($host->ran as $i => $cmd) {
            if (in_array($cmd[0], ['ufw', 'fail2ban-client'], true)) {
                $steps[] = [$cmd[0], $cmd[1], $host->held[$i]];
            }
        }
        $this->assertSame([
            ['ufw', 'delete', true],
            ['fail2ban-client', 'unban', false],
            ['ufw', 'prepend', true],
        ], array_slice($steps, 0, 3));
    }

    public function test_a_lock_held_elsewhere_fails_loudly_and_writes_nothing(): void
    {
        $host = $this->host();
        $other = fopen($this->lock, 'r');
        $this->assertNotFalse($other);
        flock($other, LOCK_EX);

        try {
            $this->firewall($host, lockWait: 1)->addRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']));
            $this->fail('the rule was written without the lock');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Another firewall change held ' . $this->lock . ' for over 1 s; nothing was changed', $e->getMessage());
        } finally {
            fclose($other);
        }

        $this->assertSame([], $this->ufwWrites($host));
    }

    public function test_a_lock_core_cannot_open_is_made_on_the_host_first(): void
    {
        $host = $this->host();
        $missing = sys_get_temp_dir() . '/ufw-lock-' . bin2hex(random_bytes(4)) . '/ufw.lock';

        try {
            (new UfwFirewall($host, $missing))->enable();
            $this->fail('enabled without the lock');
        } catch (FirewallException $e) {
            $this->assertSame('Could not open the firewall lock ' . $missing, $e->getMessage());
        }

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([['sh', '-c', 'mkdir -p "$(dirname "$0")" && touch "$0" && chmod 0644 "$0"', $missing]], $host->ran);
    }
}
