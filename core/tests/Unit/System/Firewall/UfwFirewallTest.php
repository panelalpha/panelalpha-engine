<?php

namespace Tests\Unit\System\Firewall;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\Firewall\FirewallClash;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\TrustedAddress;
use App\System\Firewall\Ufw\UfwFirewall;
use App\System\Firewall\Ufw\UfwRules;
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

    /**
     * A host whose ufw answers from $rules and records every command. Once
     * ufw writes, the store reads as $written, when given: the rule is there
     * to read back, and was not there to clash with. With $deleted, a ufw
     * delete leaves the store as $deleted instead. With $unbanned set, the
     * store reads as that once fail2ban is told to unban: it banned again.
     */
    private function host(string $rules = self::OFFICE . "\n" . self::BAN, string $ufwOut = 'Rule added', int $ufwCode = 0, ?string $written = null, ?string $deleted = null): ProcessRunner
    {
        return new class ($rules, $ufwOut, $ufwCode, $this->lock, $written, $deleted) implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ran = [];
            /** @var list<bool> whether the ufw lock was held, per command in $ran */
            public array $held = [];
            public ?string $unbanned = null;

            public function __construct(public string $rules, public string $ufwOut, public int $ufwCode, private string $lock, public ?string $written = null, public ?string $deleted = null)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = (array) $cmd;
                $this->ran[] = $cmd;
                $this->held[] = UfwFirewallTest::lockHeld($this->lock);
                if ($cmd[0] === 'ufw' && !in_array($cmd[1] ?? '', ['version', 'status'], true)) {
                    if ($this->deleted !== null && in_array('delete', $cmd, true)) {
                        $this->rules = $this->deleted;
                    } elseif ($this->written !== null) {
                        [$this->rules, $this->written] = [$this->written, null];
                    }
                }
                if ($cmd[0] === 'fail2ban-client' && ($cmd[1] ?? '') === 'unban' && $this->unbanned !== null) {
                    $this->rules = $this->unbanned;
                }
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

    /**
     * A host whose ufw keeps its rules the way ufw does, one per match: an insert
     * whose match is held is skipped, an append replaces a rule that differs only
     * in its action or comment, and a delete that names no comment takes the rule
     * whatever its comment. IPv4 only. A write matching $refuse is refused. With
     * $otherEdit set, another writer's rules replace the store right after the
     * first read made while the ufw lock is free: that writer took the lock first.
     * With $unbanned set, the store is that once fail2ban is told to unban: it
     * banned the address again.
     */
    private function ufwStore(string $rules, ?string $refuse = null): ProcessRunner
    {
        return new class ($rules, $refuse, $this->lock) implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ran = [];
            /** @var list<bool> */
            public array $held = [];
            /** @var list<string> tuples, without "### tuple ### " */
            public array $tuples;
            /** @var list<string>|null */
            public ?array $otherEdit = null;
            /** @var list<string>|null */
            public ?array $unbanned = null;

            public function __construct(string $rules, private ?string $refuse, private string $lock)
            {
                $this->tuples = array_values(array_filter(array_map(static fn (string $l): string => (string) preg_replace('/^### tuple ### /', '', $l), explode("\n", $rules))));
            }

            public function landOtherEdit(): void
            {
                if ($this->otherEdit !== null) {
                    [$this->tuples, $this->otherEdit] = [$this->otherEdit, null];
                }
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = (array) $cmd;
                $this->ran[] = $cmd;
                $this->held[] = $held = UfwFirewallTest::lockHeld($this->lock);
                if ($cmd === ['cat', '/etc/ufw/user.rules']) {
                    $read = FakeProcess::ok(implode('', array_map(static fn (string $t): string => "### tuple ### {$t}\n", $this->tuples)));
                    if (!$held) {
                        $this->landOtherEdit();
                    }

                    return $read;
                }
                if ($cmd[0] === 'fail2ban-client' && ($cmd[1] ?? '') === 'unban' && $this->unbanned !== null) {
                    $this->tuples = $this->unbanned;
                }
                if ($cmd[0] !== 'ufw') {
                    return $cmd === ['cat', '/etc/ufw/user6.rules'] ? FakeProcess::failed('No such file') : FakeProcess::ok();
                }
                if ($this->refuse !== null && preg_match($this->refuse, implode(' ', $cmd)) === 1) {
                    return FakeProcess::of(1, 'ERROR: Bad rule');
                }

                return FakeProcess::ok($this->write(array_slice($cmd, 1)));
            }

            /** @param list<string> $a */
            private function write(array $a): string
            {
                $route = $a[0] === 'route';
                if ($route) {
                    array_shift($a);
                }
                $verb = in_array($a[0], ['prepend', 'delete'], true) ? array_shift($a) : 'append';
                $action = (string) array_shift($a);
                $dir = !$route && in_array($a[0] ?? '', ['in', 'out'], true) ? (string) array_shift($a) : 'in';
                $kv = ['proto' => 'any', 'from' => 'any', 'to' => 'any', 'port' => 'any', 'comment' => ''];
                for ($i = 0; $i + 1 < count($a); $i += 2) {
                    $kv[$a[$i]] = $a[$i + 1];
                }
                $any = static fn (string $v): string => $v === 'any' ? '0.0.0.0/0' : $v;
                $match = implode(' ', [$kv['proto'], $kv['port'], $any($kv['to']), 'any', $any($kv['from']), $dir]);
                $new = ($route ? 'route:' : '') . "{$action} {$match}" . ($kv['comment'] === '' ? '' : ' comment=' . bin2hex($kv['comment']));
                foreach ($this->tuples as $i => $t) {
                    preg_match('/^(route:)?(\S+) (.*?)(?: comment=(\S+))?$/', $t, $m);
                    if (($m[1] !== '') !== $route || $m[3] !== $match) {
                        continue;
                    }
                    $same = $m[2] === $action && (string) hex2bin($m[4] ?? '') === $kv['comment'];
                    if ($verb === 'delete') {
                        if ($m[2] === $action && ($same || $kv['comment'] === '')) {
                            array_splice($this->tuples, $i, 1);

                            return 'Rule deleted';
                        }

                        continue;
                    }
                    if ($verb === 'prepend') {
                        return 'Skipping inserting existing rule';
                    }
                    if ($same) {
                        return 'Skipping adding existing rule';
                    }
                    $this->tuples[$i] = $new;

                    return 'Rule updated';
                }
                if ($verb === 'delete') {
                    return 'Could not delete non-existent rule';
                }
                if ($verb === 'prepend') {
                    array_unshift($this->tuples, $new);

                    return 'Rule inserted';
                }
                $this->tuples[] = $new;

                return 'Rule added';
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
        $host = $this->host(self::OFFICE, written: self::OFFICE . "\n" . self::BAN);
        $rule = ($this->firewall($host))->addRule(FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9/32']));

        $this->assertSame(['ufw', 'prepend', 'deny', 'in', 'from', '198.51.100.9', 'to', 'any'], $this->ufwCalls($host)[0]);
        $this->assertSame(['ufw', 'route', 'prepend', 'deny', 'from', '198.51.100.9', 'to', 'any'], $this->ufwCalls($host)[1], 'and above the published ports\' allows');
        $this->assertSame('198.51.100.9', $rule->source);
    }

    public function test_an_allow_is_appended(): void
    {
        $host = $this->host(self::BAN, written: self::OFFICE . "\n" . self::BAN);
        ($this->firewall($host))->addRule(FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']));

        $this->assertSame('allow', $this->ufwCalls($host)[0][1]);
    }

    public function test_a_rule_for_published_ports_is_a_ufw_route_rule(): void
    {
        $routes = "### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in\n### tuple ### route:allow tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in";
        $allow = '### tuple ### route:allow tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in';
        $host = $this->host(self::OFFICE, written: self::OFFICE . "\n" . $allow);
        $firewall = $this->firewall($host);
        $rule = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '8080', 'scope' => 'published']);

        $this->assertSame('published', $firewall->addRule($rule)->scope);
        /** @var object{written: ?string} $host */
        $host->written = self::OFFICE . "\n" . $routes;
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

    public function test_deleting_an_allow_that_kept_a_bans_comment_leaves_fail2ban_alone(): void
    {
        // A ban made an allow keeps fail2ban's comment; fail2ban may hold a new ban for the address.
        $host = $this->host('### tuple ### allow any any 0.0.0.0/0 any 198.51.100.9 in comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd'));

        $this->firewall($host)->deleteRule(FirewallRule::fromArray(['action' => 'allow', 'source' => '198.51.100.9'])->id());

        /** @var object{ran: list<list<string>>} $host */
        $this->assertSame([['ufw', 'delete', 'allow', 'in', 'from', '198.51.100.9', 'to', 'any']], $this->ufwCalls($host));
        $this->assertSame([], array_values(array_filter($host->ran, fn (array $c): bool => $c[0] === 'fail2ban-client')));
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
        $host = $this->host(written: self::OFFICE . "\n" . self::BAN . "\n### tuple ### allow tcp 2222 0.0.0.0/0 any 203.0.113.7 in");
        $firewall = $this->firewall($host);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $new = $firewall->updateRule($office->id(), $office->with(['port' => '2222']));

        $calls = $this->ufwCalls($host);
        $this->assertSame('allow', $calls[0][1]);
        $this->assertSame('delete', $calls[1][1]);
        $this->assertSame('2222', $new->port);
    }

    public function test_an_edit_that_keeps_one_of_the_old_rules_ufw_rules_takes_the_old_one_out_first(): void
    {
        // ufw keeps one rule per match: added first, the new rule would be skipped or merged into the old one.
        $c = 'comment=' . bin2hex('office');
        $deny = "### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in\n### tuple ### route:deny any any 0.0.0.0/0 any 203.0.113.7 in";
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'comment' => 'office']);
        $both = FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7']);
        $cases = [
            'a new action' => [self::OFFICE, $office, $office->with(['action' => 'deny']), "### tuple ### deny tcp 22 0.0.0.0/0 any 203.0.113.7 in {$c}\n### tuple ### route:deny tcp 22 0.0.0.0/0 any 203.0.113.7 in {$c}", [
                ['ufw', 'delete', 'allow', 'in', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '22'],
                ['ufw', 'prepend', 'deny', 'in', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '22', 'comment', 'office'],
                ['ufw', 'route', 'prepend', 'deny', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '22', 'comment', 'office'],
            ]],
            'both ways' => [$deny, $both, $both->with(['direction' => 'both']), self::BOTH, [
                ['ufw', 'delete', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'],
                ['ufw', 'route', 'delete', 'deny', 'from', '203.0.113.7', 'to', 'any'],
                ['ufw', 'prepend', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'],
                ['ufw', 'prepend', 'deny', 'out', 'from', 'any', 'to', '203.0.113.7'],
                ['ufw', 'route', 'prepend', 'deny', 'from', '203.0.113.7', 'to', 'any'],
            ]],
            'published ports only' => [$deny, $both, $both->with(['scope' => 'published']), '### tuple ### route:deny any any 0.0.0.0/0 any 203.0.113.7 in', [
                ['ufw', 'delete', 'deny', 'in', 'from', '203.0.113.7', 'to', 'any'],
                ['ufw', 'route', 'delete', 'deny', 'from', '203.0.113.7', 'to', 'any'],
                ['ufw', 'route', 'prepend', 'deny', 'from', '203.0.113.7', 'to', 'any'],
            ]],
        ];
        foreach ($cases as $case => [$rules, $old, $edit, $written, $calls]) {
            $host = $this->host($rules, written: $written);

            $new = $this->firewall($host)->updateRule($old->id(), $edit);

            $this->assertSame($edit->id(), $new->id(), $case);
            $this->assertSame($calls, $this->ufwCalls($host), $case);
        }
    }

    public function test_an_edit_that_keeps_one_of_its_ufw_rules_is_still_refused_onto_another_rule(): void
    {
        $app = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'scope' => 'published']);
        $host = $this->host(self::OFFICE . "\n### tuple ### route:allow tcp 22 0.0.0.0/0 any 203.0.113.7 in");
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        try {
            // As a deny it would cover published ports too, where the allow for the app holds that match.
            $this->firewall($host)->updateRule($office->id(), $office->with(['action' => 'deny']));
            $this->fail('an edit onto ' . $app->id() . ' went through');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Rule ' . $app->id() . ' ', $e->getMessage());
        }
        $this->assertSame([], $this->ufwWrites($host), 'the old rule is still there');
    }

    public function test_a_ban_edited_in_place_is_lifted_in_fail2ban_before_the_new_rule_goes_in(): void
    {
        $ban = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']);
        $written = "### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in\n### tuple ### deny any any 198.51.100.9 any 0.0.0.0/0 out\n### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in";
        $host = $this->host('### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd'), written: $written, deleted: '');

        $this->firewall($host)->updateRule($ban->id(), $ban->with(['direction' => 'both']));

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
            ['ufw', 'prepend', true],
            ['ufw', 'route', true],
        ], $steps);
    }

    public function test_a_ban_fail2ban_makes_again_while_its_edit_waits_for_the_lock_is_left_as_it_is(): void
    {
        $f2b = 'comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd');
        $banned = "### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b}\n### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b}";
        $ban = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']);
        $edits = [
            'a deny both ways, whose inbound halves ufw would skip' => [$ban->with(['direction' => 'both']), ['prepend', 'deny', 'out']],
            'an allow, which ufw would write over the new ban' => [$ban->with(['action' => 'allow', 'scope' => 'host']), ['allow', 'in']],
        ];
        foreach ($edits as $case => [$edit, $newHalf]) {
            // The ban is back by the time the old rule is gone: fail2ban banned the address again.
            $host = $this->host($banned, deleted: $banned);

            try {
                $this->firewall($host)->updateRule($ban->id(), $edit);
                $this->fail("{$case}: the edit went in over the new ban");
            } catch (FirewallException $e) {
                $this->assertStringContainsString('Rule ' . $ban->id() . ' (deny in from 198.51.100.9 on host and published ports) already matches', $e->getMessage(), $case);
            }
            $this->assertSame([], array_values(array_filter($this->ufwCalls($host), fn (array $c): bool => array_slice($c, 1, count($newHalf)) === $newHalf)), "{$case}: nothing of the edit was written");
        }
    }

    public function test_a_refused_edit_of_a_ban_is_reported_as_refused_when_the_old_ban_cannot_read_back_whole(): void
    {
        $old = 'comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd');
        $new = 'comment=' . bin2hex('by Fail2Ban after 3 attempts against sshd');
        $outbound = "### tuple ### deny any any 198.51.100.9 any 0.0.0.0/0 out {$old}";
        $both = "### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in {$old}\n{$outbound}\n### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$old}";
        $rebanned = "### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in {$new}\n### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$new}";
        $ban = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '198.51.100.9']);
        $reban = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']);
        // fail2ban bans the address again between the locks; putting the old ban back adds only its outbound half.
        $host = $this->host($both, written: "{$rebanned}\n{$outbound}", deleted: $rebanned);

        try {
            $this->firewall($host)->updateRule($ban->id(), $ban->with(['direction' => 'in']));
            $this->fail('the edit went in over the new ban');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Rule ' . $reban->id() . ' (deny in from 198.51.100.9 on host and published ports) already matches', $e->getMessage());
        }
        $this->assertContains(['ufw', 'prepend', 'deny', 'out', 'from', 'any', 'to', '198.51.100.9', 'comment', 'by Fail2Ban after 5 attempts against sshd'], $this->ufwCalls($host), 'the old ban was put back');
    }

    public function test_an_allow_that_kept_a_bans_comment_is_edited_under_one_lock_without_lifting_a_new_ban(): void
    {
        // Under two locks with an unban between them, fail2ban could ban the address again and the
        // allow, put back after the refusal or re-added, would be appended over the new ban.
        $f2b = 'comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd');
        $new = 'comment=' . bin2hex('by Fail2Ban after 3 attempts against sshd');
        $allow = FirewallRule::fromArray(['action' => 'allow', 'source' => '198.51.100.9', 'comment' => 'by Fail2Ban after 5 attempts against sshd']);
        $edits = [
            'made a deny' => [$allow->with(['action' => 'deny']), "### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b}\n### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b}", [
                ['ufw', 'delete', true],
                ['ufw', 'prepend', true],
                ['ufw', 'route', true],
            ]],
            'given a new comment' => [$allow->with(['comment' => 'office']), '### tuple ### allow any any 0.0.0.0/0 any 198.51.100.9 in comment=' . bin2hex('office'), [
                ['ufw', 'delete', true],
                ['ufw', 'allow', true],
            ]],
        ];
        foreach ($edits as $case => [$edit, $written, $steps]) {
            $host = $this->host("### tuple ### allow any any 0.0.0.0/0 any 198.51.100.9 in {$f2b}", written: $written, deleted: '');
            /** @var object{ran: list<list<string>>, held: list<bool>, unbanned: ?string} $host */
            $host->unbanned = "### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in {$new}\n### tuple ### route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$new}";

            $rule = $this->firewall($host)->updateRule($allow->id(), $edit);

            $this->assertSame($edit->id(), $rule->id(), $case);
            $ran = [];
            foreach ($host->ran as $i => $cmd) {
                if (in_array($cmd[0], ['ufw', 'fail2ban-client'], true)) {
                    $ran[] = [$cmd[0], $cmd[1], $host->held[$i]];
                }
            }
            $this->assertSame($steps, $ran, "{$case}: one lock, and fail2ban is not told to unban");
        }
    }

    /** The engine's own SSH allow, as scripts/firewall/ufw.sh writes it. */
    private const SSH = '### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in comment=70616e656c616c7068613a20737368';

    /** Adds $rule to a host holding $rules, expecting a refusal that names $existing; returns the message. */
    private function refusedAdd(string $rules, FirewallRule $rule, FirewallRule $existing): string
    {
        $host = $this->host($rules);
        try {
            $this->firewall($host)->addRule($rule);
            $this->fail('a rule clashing with ' . $existing->id() . ' was written');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Rule ' . $existing->id() . ' ', $e->getMessage());
        }
        $this->assertSame([], $this->ufwWrites($host), 'ufw was not asked to write anything');

        return $e->getMessage();
    }

    public function test_a_duplicate_of_an_engine_rule_is_refused_and_the_rule_keeps_its_comment(): void
    {
        $ssh = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'comment' => 'panelalpha: ssh']);

        $message = $this->refusedAdd(self::SSH, $ssh->with(['comment' => 'qa211-fw09-dup-ssh']), $ssh);
        $this->refusedAdd(self::SSH, $ssh->with(['comment' => null]), $ssh);

        $this->assertSame("Rule {$ssh->id()} (allow in tcp 22) already matches this traffic and is left as it is; the engine opened it and needs it.", $message);
    }

    public function test_a_duplicate_of_any_rule_is_refused_whatever_its_comment(): void
    {
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'comment' => 'office']);

        $message = $this->refusedAdd(self::OFFICE, $office->with(['comment' => 'home']), $office);
        $this->refusedAdd(self::OFFICE, $office, $office);

        $this->assertStringEndsWith('change or delete that rule instead.', $message);
    }

    public function test_a_rule_that_differs_only_in_its_action_is_refused(): void
    {
        // Appended, ufw would turn the deny into the allow; inserted, it would skip the host's deny and write only the route one.
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'comment' => 'office']);
        $ban = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9']);

        $this->refusedAdd(self::OFFICE, $office->with(['action' => 'deny']), $office);
        $this->refusedAdd(self::BAN, $ban->with(['action' => 'allow', 'scope' => 'host']), $ban);
    }

    public function test_a_rule_sharing_one_ufw_rule_with_another_is_refused(): void
    {
        $published = '### tuple ### route:deny any any 0.0.0.0/0 any 203.0.113.7 in';
        $outbound = '### tuple ### deny any any 203.0.113.7 any 0.0.0.0/0 out';
        $deny = FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7']);

        $this->refusedAdd($published, $deny, FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7', 'scope' => 'published']));
        $this->refusedAdd($outbound, $deny->with(['direction' => 'both']), FirewallRule::fromArray(['action' => 'deny', 'direction' => 'out', 'destination' => '203.0.113.7']));
    }

    public function test_a_rule_written_on_the_host_is_not_written_over_either(): void
    {
        $limit = '### tuple ### limit tcp 22 0.0.0.0/0 any 0.0.0.0/0 in';

        $message = $this->refusedAdd($limit, FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22']), UfwRules::parse($limit)[0]);

        $this->assertStringContainsString('(limit tcp 22 any any any in)', $message);
    }

    public function test_a_refusal_says_which_ports_the_rule_already_there_is_for(): void
    {
        $api = '### tuple ### route:allow tcp 2011 0.0.0.0/0 any 0.0.0.0/0 in comment=' . bin2hex('panelalpha: engine api');
        $deny = "### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in\n### tuple ### route:deny any any 0.0.0.0/0 any 203.0.113.7 in";
        $published = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'scope' => 'published']);
        $both = FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7']);

        $this->assertStringContainsString('(allow in tcp 2011 on published ports)', $this->refusedAdd($api, $published->with(['comment' => 'mine']), $published));
        $this->assertStringContainsString('(deny in from 203.0.113.7 on host and published ports)', $this->refusedAdd($deny, $both->with(['action' => 'allow', 'scope' => 'published']), $both));
    }

    public function test_a_rule_that_matches_nothing_already_there_is_added(): void
    {
        $host = $this->host(self::SSH . "\n" . self::OFFICE, written: self::SSH . "\n" . self::OFFICE . "\n### tuple ### allow tcp 22 0.0.0.0/0 any 198.51.100.9 in");

        $added = $this->firewall($host)->addRule(FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '198.51.100.9']));

        $this->assertSame('198.51.100.9', $added->source);
        $this->assertCount(1, $this->ufwWrites($host));
    }

    public function test_a_port_list_is_written_and_read_back_in_the_order_ufw_keeps(): void
    {
        $host = $this->host('', written: '### tuple ### allow tcp 80,443 0.0.0.0/0 any 192.0.2.1 in');

        $added = $this->firewall($host)->addRule(FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '443,80', 'source' => '192.0.2.1']));

        $this->assertSame('80,443', $added->port);
        $this->assertSame(['ufw', 'allow', 'in', 'proto', 'tcp', 'from', '192.0.2.1', 'to', 'any', 'port', '80,443'], $this->ufwCalls($host)[0]);
    }

    public function test_an_edit_that_would_match_another_rule_is_refused_and_changes_nothing(): void
    {
        $other = '### tuple ### allow tcp 2222 0.0.0.0/0 any 203.0.113.7 in';
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);
        $ssh = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'comment' => 'panelalpha: ssh']);
        $cases = [
            [self::OFFICE . "\n" . $other, FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2222', 'source' => '203.0.113.7']), $office->with(['port' => '2222']), 'change or delete that rule instead'],
            [self::OFFICE . "\n" . self::SSH, $ssh, $office->with(['source' => null]), 'the engine opened it and needs it'],
        ];
        foreach ($cases as [$rules, $existing, $edit, $why]) {
            $host = $this->host($rules);
            try {
                $this->firewall($host)->updateRule($office->id(), $edit);
                $this->fail('an edit onto ' . $existing->id() . ' went through');
            } catch (FirewallException $e) {
                $this->assertStringContainsString('Rule ' . $existing->id() . ' ', $e->getMessage());
                $this->assertStringContainsString($why, $e->getMessage());
            }
            $this->assertSame([], $this->ufwWrites($host));
        }
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

        ($this->firewall($this->host(self::OFFICE, ufwOut: "ERROR: Invalid position '1'", ufwCode: 1)))
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

    public function test_an_edit_reads_the_rule_under_the_lock_and_puts_back_what_it_took_out(): void
    {
        // Another edit of the rule gets the lock first. Read before taking the lock, this edit
        // deleted that edit's rule and, refused, put back the copy it had read.
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'comment' => 'office']);
        $home = 'allow tcp 22 0.0.0.0/0 any 203.0.113.7 in comment=' . bin2hex('home');
        $host = $this->ufwStore(self::OFFICE, refuse: '/ comment bad$/');
        /** @var object{ran: list<list<string>>, held: list<bool>, tuples: list<string>, otherEdit: ?list<string>} $host */
        $host->otherEdit = [$home];

        try {
            $this->firewall($host)->updateRule($office->id(), $office->with(['comment' => 'bad']));
            $this->fail('ufw refused the new comment');
        } catch (FirewallException $e) {
            $this->assertStringContainsString('Bad rule', $e->getMessage());
        }
        // Still waiting for the lock: it goes in now.
        $host->landOtherEdit();

        $this->assertSame([$home], $host->tuples, 'the other edit is what is left, not a stale copy');
        $reads = array_keys($host->ran, ['cat', '/etc/ufw/user.rules'], true);
        $this->assertNotSame([], $reads);
        $this->assertSame(array_fill(0, count($reads), true), array_map(fn (int $i): bool => $host->held[$i], $reads), 'every read of the rules was under the lock');
    }

    public function test_an_edit_of_a_ban_that_keeps_its_match_is_refused_when_fail2ban_bans_again_meanwhile(): void
    {
        // fail2ban bans the address again between the edit's two locks. Such an edit was not checked
        // again: the new rule did not read back (404), or the answer was the new ban as if edited.
        $f2b = static fn (int $n): string => 'comment=' . bin2hex("by Fail2Ban after {$n} attempts against sshd");
        $rebanned = ["deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(3)}", "route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(3)}"];
        $comment = 'by Fail2Ban after 5 attempts against sshd';
        $published = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9', 'scope' => 'published', 'comment' => $comment]);
        $both = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '198.51.100.9', 'comment' => $comment]);
        $ban = FirewallRule::fromArray(['action' => 'deny', 'source' => '198.51.100.9', 'comment' => $comment]);
        $out = "deny any any 198.51.100.9 any 0.0.0.0/0 out {$f2b(5)}";
        $cases = [
            'a ban on published ports only, sent back unchanged' => [["route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}"], $published, $published->with([]), $rebanned],
            // Put back as far as ufw takes it beside the new ban: its outbound half.
            'a ban both ways, sent back unchanged' => [[$out, "deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}", "route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}"], $both, $both->with([]), [$out, ...$rebanned]],
            'a ban given a new comment' => [["deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}", "route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}"], $ban, $ban->with(['comment' => 'keep out']), $rebanned],
        ];
        foreach ($cases as $case => [$tuples, $old, $edit, $left]) {
            $host = $this->ufwStore('### tuple ### ' . implode("\n### tuple ### ", $tuples));
            /** @var object{tuples: list<string>, unbanned: ?list<string>} $host */
            $host->unbanned = $rebanned;

            try {
                $this->firewall($host)->updateRule($old->id(), $edit);
                $this->fail("{$case}: the edit went in");
            } catch (FirewallClash $e) {
                $this->assertStringContainsString('Rule ' . $ban->id() . ' (deny in from 198.51.100.9 on host and published ports) already matches', $e->getMessage(), $case);
            }
            $this->assertSame($left, $host->tuples, "{$case}: the new ban is left as it is");
        }

        // fail2ban bans again as it did before, the usual case: the new ban is part of the rule
        // asked for, or all of it, and ufw writes the rest.
        $again = ["deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}", "route:deny any any 0.0.0.0/0 any 198.51.100.9 in {$f2b(5)}"];
        foreach (['a ban' => [$again, $ban, $again], 'a ban both ways' => [[$out, ...$again], $both, [$out, ...$again]]] as $case => [$tuples, $old, $left]) {
            $host = $this->ufwStore('### tuple ### ' . implode("\n### tuple ### ", $tuples));
            /** @var object{tuples: list<string>, unbanned: ?list<string>} $host */
            $host->unbanned = $again;

            $this->assertSame($old->toArray(), $this->firewall($host)->updateRule($old->id(), $old->with([]))->toArray(), $case);
            $this->assertSame($left, $host->tuples, $case);
        }
    }

    public function test_a_rule_that_would_be_listed_as_one_with_another_is_refused(): void
    {
        // ufw keeps nothing that tells these from the halves of one rule both ways or on both scopes,
        // so the new rule read back as that rule, under neither id: the API answered 404.
        $c = static fn (string $comment): string => 'comment=' . bin2hex($comment);
        $denyIn = static fn (string $port, string $comment): array => ["deny tcp {$port} 0.0.0.0/0 any 192.0.2.1 in {$c($comment)}", "route:deny tcp {$port} 0.0.0.0/0 any 192.0.2.1 in {$c($comment)}"];
        $parse = static fn (string $tuple): FirewallRule => UfwRules::parse("### tuple ### {$tuple}")[0];
        $outbound = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'out', 'protocol' => 'tcp', 'port' => '3306', 'destination' => '192.0.2.1']);
        $allowOut = $parse("allow tcp 443 192.0.2.1 any 0.0.0.0/0 out {$c('b')}");
        $denyOut = $parse("deny tcp 25 192.0.2.1 any 0.0.0.0/0 out {$c('a')}");
        $hostOnly = $parse("deny any any 0.0.0.0/0 any 192.0.2.5 in {$c('c')}");
        $cases = [
            'the other direction of a rule there' => [['deny tcp 3306 192.0.2.1 any 0.0.0.0/0 out'], null, FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '3306', 'source' => '192.0.2.1']), $outbound, 'both ways'],
            'an edit of its action' => [["allow tcp 443 192.0.2.1 any 0.0.0.0/0 out {$c('b')}", ...$denyIn('443', 'b')], $allowOut, $allowOut->with(['action' => 'deny']), FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '443', 'source' => '192.0.2.1']), 'both ways'],
            'an edit of its comment' => [["deny tcp 25 192.0.2.1 any 0.0.0.0/0 out {$c('a')}", ...$denyIn('25', 'b')], $denyOut, $denyOut->with(['comment' => 'b']), FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '25', 'source' => '192.0.2.1']), 'both ways'],
            'a published deny beside a host one' => [["deny any any 0.0.0.0/0 any 192.0.2.5 in {$c('c')}"], null, FirewallRule::fromArray(['action' => 'deny', 'source' => '192.0.2.5', 'scope' => 'published', 'comment' => 'c']), $hostOnly, 'on host and published ports'],
        ];
        foreach ($cases as $case => [$tuples, $old, $rule, $there, $how]) {
            $host = $this->ufwStore('### tuple ### ' . implode("\n### tuple ### ", $tuples));

            try {
                $old === null ? $this->firewall($host)->addRule($rule) : $this->firewall($host)->updateRule($old->id(), $rule);
                $this->fail("{$case}: the rule went in");
            } catch (FirewallClash $e) {
                $this->assertStringContainsString("Rule {$there->id()} (", $e->getMessage(), $case);
                $this->assertStringContainsString("and this one would be listed as a single rule {$how}", $e->getMessage(), $case);
            }
            /** @var object{tuples: list<string>} $host */
            $this->assertSame($tuples, $host->tuples, "{$case}: nothing changed");
        }
    }

    public function test_an_edit_into_the_other_direction_of_itself_takes_the_old_rule_out_first(): void
    {
        // Added beside the old rule, the new one would read back as one rule both ways with it.
        $c = 'comment=' . bin2hex('c');
        $old = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'out', 'protocol' => 'tcp', 'port' => '22', 'destination' => '192.0.2.1', 'comment' => 'c']);
        $host = $this->ufwStore("### tuple ### deny tcp 22 192.0.2.1 any 0.0.0.0/0 out {$c}");
        $edit = $old->with(['direction' => 'in', 'source' => '192.0.2.1', 'destination' => null]);

        $new = $this->firewall($host)->updateRule($old->id(), $edit);

        $this->assertSame($edit->id(), $new->id());
        /** @var object{tuples: list<string>} $host */
        $this->assertSame(["route:deny tcp 22 0.0.0.0/0 any 192.0.2.1 in {$c}", "deny tcp 22 0.0.0.0/0 any 192.0.2.1 in {$c}"], $host->tuples);
    }

    private const BOTH = "### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in\n### tuple ### deny any any 203.0.113.7 any 0.0.0.0/0 out\n### tuple ### route:deny any any 0.0.0.0/0 any 203.0.113.7 in";

    public function test_a_deny_both_ways_is_added_and_deleted_as_its_host_and_route_rules(): void
    {
        $host = $this->host('', written: self::BOTH);
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
        $host = $this->host(self::OFFICE, written: self::OFFICE . "\n" . self::BOTH);
        $firewall = $this->firewall($host);
        $both = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7']);
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $firewall->addRule($both);
        $firewall->deleteRule($both->id());
        $firewall->updateRule($office->id(), $office->with(['comment' => 'home']));
        /** @var object{written: ?string} $host */
        $host->written = self::OFFICE . "\n" . self::BOTH . "\n### tuple ### allow tcp 2222 0.0.0.0/0 any 203.0.113.7 in";
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
        $host = $this->host('', written: self::BOTH);

        $this->firewall($host)->addRule(FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7']));

        /** @var object{ran: list<list<string>>, held: list<bool>} $host */
        $this->assertSame(['cat', 'cat', 'ufw', 'ufw', 'ufw', 'cat', 'cat'], array_column($host->ran, 0));
        $this->assertSame([true, true, true, true, true, true, true], $host->held, 'the check for a clashing rule, all three halves and the read-back');
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
        $host = $this->ufwStore('### tuple ### deny any any 0.0.0.0/0 any 198.51.100.9 in comment=' . bin2hex('by Fail2Ban after 5 attempts against sshd'));

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
