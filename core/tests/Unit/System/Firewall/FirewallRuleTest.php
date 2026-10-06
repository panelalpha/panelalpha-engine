<?php

namespace Tests\Unit\System\Firewall;

use App\System\Firewall\FirewallRule;
use PHPUnit\Framework\TestCase;

class FirewallRuleTest extends TestCase
{
    public function test_addresses_are_spelled_the_way_firewalls_store_them(): void
    {
        $this->assertSame('1.2.3.4', FirewallRule::normalizeAddress('1.2.3.4/32'));
        $this->assertSame('10.0.0.0/8', FirewallRule::normalizeAddress('10.1.2.3/8'));
        $this->assertSame('192.168.1.0/24', FirewallRule::normalizeAddress('192.168.1.77/24'));
        $this->assertSame('2001:db8::1', FirewallRule::normalizeAddress('2001:0db8:0:0::1/128'));
        $this->assertSame('2001:db8::/32', FirewallRule::normalizeAddress('2001:db8:1::/32'));
        $this->assertSame('0.0.0.0/0', FirewallRule::normalizeAddress('9.9.9.9/0'));
        $this->assertSame('example.com', FirewallRule::normalizeAddress('example.com'));
    }

    public function test_only_addresses_and_ranges_are_addresses(): void
    {
        $this->assertTrue(FirewallRule::isAddress('203.0.113.7'));
        $this->assertTrue(FirewallRule::isAddress('203.0.113.0/24'));
        $this->assertTrue(FirewallRule::isAddress('::1'));
        $this->assertFalse(FirewallRule::isAddress('example.com'));
        $this->assertFalse(FirewallRule::isAddress('1.2.3.4/33'));
        $this->assertFalse(FirewallRule::isAddress('1.2.3.4/x'));
    }

    public function test_a_rule_on_nothing_and_a_range_without_a_protocol_are_refused(): void
    {
        $this->assertArrayHasKey('port', FirewallRule::fromArray(['action' => 'allow'])->problems());
        $this->assertArrayHasKey('protocol', FirewallRule::fromArray(['action' => 'allow', 'port' => '30000:30009'])->problems());
        $this->assertArrayHasKey('protocol', FirewallRule::fromArray(['action' => 'allow', 'port' => '80,443'])->problems());
        $this->assertSame([], FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7'])->problems());
        $this->assertSame([], FirewallRule::fromArray(['action' => 'allow', 'port' => '22'])->problems());
        $this->assertSame([], FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7'])->problems());
        $this->assertArrayHasKey('source', FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'port' => '25'])->problems());
        $this->assertArrayHasKey('source', FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7', 'destination' => '198.51.100.1'])->problems());
    }

    public function test_an_address_that_is_every_address_is_refused(): void
    {
        // ufw would take it for the rule on any address, the engine's own included.
        foreach (['0.0.0.0/0', '::/0', '203.0.113.7/0', '2001:db8::1/0'] as $every) {
            $this->assertArrayHasKey('source', FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => $every])->problems(), $every);
            $this->assertArrayHasKey('destination', FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '22', 'destination' => $every])->problems(), $every);
        }
        // Not a deny of every connection either.
        $this->assertSame(
            ['source' => 'The source 0.0.0.0/0 is every address; leave it out to match any address.'],
            FirewallRule::fromArray(['action' => 'deny', 'source' => '0.0.0.0/0'])->problems()
        );
        $this->assertSame([], FirewallRule::fromArray(['action' => 'deny', 'source' => '0.0.0.0'])->problems());
        $this->assertSame([], FirewallRule::fromArray(['action' => 'deny', 'source' => '0.0.0.0/1'])->problems());
    }

    public function test_a_port_list_is_in_the_order_ufw_stores_it(): void
    {
        $this->assertSame('80,443', FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '443,80'])->port);
        $this->assertSame('22,80,8000:8010', FirewallRule::normalizePort('8000:8010,80,22'));
        $this->assertSame('80:90,8000', FirewallRule::normalizePort('8000,80:90'));
        $this->assertSame('80,80:85,80:90', FirewallRule::normalizePort('80:90,80,80:85'));
        $this->assertSame('30000:30009', FirewallRule::normalizePort('30000:30009'));
        $this->assertSame(
            FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '80,443', 'source' => '192.0.2.1'])->id(),
            FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '443,80', 'source' => '192.0.2.1'])->id()
        );
    }

    public function test_the_id_follows_what_the_rule_matches_not_its_comment(): void
    {
        $rule = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7/32', 'comment' => 'office']);
        $same = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $this->assertSame($rule->id(), $same->id());
        $this->assertTrue($rule->sameMatch($rule->with(['comment' => 'home'])));
        $this->assertNotSame($rule->id(), $rule->with(['port' => '2222'])->id());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{12}\z/', $rule->id());
    }

    public function test_with_keeps_what_is_not_sent_and_clears_what_is_sent_as_null(): void
    {
        $rule = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'comment' => 'office']);

        $changed = $rule->with(['comment' => null, 'source' => '198.51.100.0/24']);

        $this->assertSame('tcp', $changed->protocol);
        $this->assertSame('22', $changed->port);
        $this->assertSame('198.51.100.0/24', $changed->source);
        $this->assertNull($changed->comment);
    }

    public function test_engine_rules_are_managed_and_not_editable(): void
    {
        $rule = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'comment' => 'panelalpha: engine api']);

        $this->assertTrue($rule->managed());
        $this->assertFalse($rule->toArray()['editable']);
        $this->assertFalse(FirewallRule::fromArray(['action' => 'allow', 'port' => '22', 'comment' => 'mine'])->managed());
    }

    public function test_a_rule_for_published_ports_is_its_own_rule_and_only_inbound(): void
    {
        $host = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '8080']);
        $published = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '8080', 'scope' => 'published']);

        $this->assertSame('host', $host->scope);
        $this->assertSame('published', $published->toArray()['scope']);
        $this->assertNotSame($host->id(), $published->id());
        // Host rules keep the ids they were listed under before rules had a scope.
        $this->assertSame(substr(sha1((string) json_encode(['allow', 'in', 'tcp', '8080', null, null])), 0, 12), $host->id());
        $this->assertSame('published', $host->with(['scope' => 'published'])->scope);

        $this->assertSame([], $published->problems());
        $this->assertArrayHasKey('direction', $published->with(['direction' => 'out'])->problems());
        $this->assertArrayHasKey('direction', FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7', 'scope' => 'published'])->problems());
    }

    public function test_a_deny_covers_both_scopes_unless_it_names_published(): void
    {
        $deny = FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7']);

        $this->assertSame('both', $deny->scope);
        $this->assertSame('both', FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7', 'scope' => 'host'])->scope);
        $this->assertSame('published', FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7', 'scope' => 'published'])->scope);
        $this->assertSame('host', FirewallRule::fromArray(['action' => 'deny', 'direction' => 'out', 'destination' => '203.0.113.7'])->scope, 'nothing published goes out');
        $this->assertSame('host', FirewallRule::fromArray(['action' => 'allow', 'port' => '22'])->scope);
        // The same id as the host deny it was before it covered published ports.
        $this->assertSame(substr(sha1((string) json_encode(['deny', 'in', null, null, '203.0.113.7', null])), 0, 12), $deny->id());
        $this->assertSame([], $deny->problems());
        $this->assertArrayHasKey('scope', $deny->with(['action' => 'allow', 'port' => '22'])->problems());
        $this->assertSame('host', $deny->with(['action' => 'allow', 'port' => '22', 'scope' => 'host'])->scope);
    }

    public function test_a_comment_ufw_would_read_as_part_of_the_rule_is_refused(): void
    {
        // Measured with `ufw --dry-run` on ufw 0.36.2, as the engine writes a host rule and a route rule.
        $host = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '25392', 'source' => '192.0.2.150']);
        $published = $host->with(['scope' => 'published']);
        $deny = FirewallRule::fromArray(['action' => 'deny', 'source' => '192.0.2.150']);
        $denyOut = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'out', 'destination' => '192.0.2.150']);

        foreach (['in', 'out', 'log', 'log-all'] as $comment) {
            foreach ([$host, $published, $deny, $denyOut] as $rule) {
                $this->assertSame(
                    "ufw takes the comment \"{$comment}\" for part of the rule; reword it.",
                    $rule->with(['comment' => $comment])->problems()['comment'] ?? null,
                    $comment
                );
            }
        }
        foreach (['--rootdir=x', '--datadir=/tmp', '--rootdir=a=b'] as $comment) {
            foreach ([$host, $published, $deny, $denyOut] as $rule) {
                $this->assertSame(
                    'ufw drops a comment that starts with --rootdir= or --datadir= before it reads the rule; reword it.',
                    $rule->with(['comment' => $comment])->problems()['comment'] ?? null,
                    $comment
                );
            }
        }
        $route = 'On a rule for published ports, which an incoming deny also is, ufw takes ';
        $words = $route . 'the word in or out followed by more words for part of the rule; reword it.';
        $routeOnly = [
            'delete' => $route . 'the comment "delete" for part of the rule; reword it.',
            'in from office' => $words,
            'block in office hours' => $words,
            'out of office' => $words,
            'keep out please' => $words,
            'x in on y out on z' => $words,
        ];
        foreach ($routeOnly as $comment => $message) {
            $this->assertSame($message, $published->with(['comment' => $comment])->problems()['comment'] ?? null, $comment);
            $this->assertSame($message, $deny->with(['comment' => $comment])->problems()['comment'] ?? null, $comment);
            $this->assertSame([], $host->with(['comment' => $comment])->problems(), $comment);
            $this->assertSame([], $denyOut->with(['comment' => $comment])->problems(), $comment);
        }
        $stored = ['IN', 'LOG', 'let them in', 'keep out', "a\tin\tb", 'x in on y', 'x app in y', 'block IN office',
            'do not delete this', 'delete 1', 'comment', 'on', 'from', 'turn on now', 'a log b', 'zażółć 日本',
            'see --rootdir=x', '--ROOTDIR=x', 'login', 'inside'];
        foreach ($stored as $comment) {
            foreach ([$host, $published, $deny, $denyOut] as $rule) {
                $this->assertSame([], $rule->with(['comment' => $comment])->problems(), $comment);
            }
        }
    }

    public function test_a_comment_that_marks_a_fail2ban_ban_is_refused_unless_it_cannot_make_a_new_ban(): void
    {
        $message = 'The comment prefix "by Fail2Ban" marks the bans fail2ban makes.';
        $deny = FirewallRule::fromArray(['action' => 'deny', 'source' => '192.0.2.160']);
        $allow = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '25392']);
        // fromArray trims, so leading blanks are the same comment.
        foreach (['by Fail2Ban', 'by Fail2Ban test', 'by Fail2Ban after 5 attempts against sshd', '  by Fail2Ban test', "\tby Fail2Ban test"] as $comment) {
            foreach ([$deny, $allow] as $rule) {
                $this->assertSame($message, $rule->with(['comment' => $comment])->problems()['comment'] ?? null, $comment);
                $this->assertSame($message, $rule->with(['comment' => $comment])->problems($rule)['comment'] ?? null, $comment);
            }
        }

        $ban = $deny->with(['comment' => 'by Fail2Ban after 5 attempts against sshd']);
        $this->assertTrue($ban->isBan());
        $this->assertSame([], $ban->with(['protocol' => 'tcp', 'port' => '22'])->problems($ban), 'a ban keeps its comment on an edit');
        $this->assertSame([], $ban->with(['source' => '192.0.2.160/32'])->problems($ban), 'the same address, spelled otherwise');
        $this->assertSame([], $ban->with(['action' => 'allow', 'scope' => 'host'])->problems($ban));
        $this->assertSame($message, $ban->with(['comment' => 'by Fail2Ban after 6 attempts against sshd'])->problems($ban)['comment'] ?? null);
        // A stored comment with trailing blanks is the comment the edit sends back trimmed.
        $stored = new FirewallRule('deny', 'in', null, null, '192.0.2.160', null, 'by Fail2Ban after 5 attempts against sshd  ', scope: FirewallRule::BOTH);
        $this->assertSame([], $stored->with(['protocol' => 'tcp', 'port' => '22'])->problems($stored));

        // An edit that keeps the comment but makes a ban of another rule, or of another address, is refused.
        $kept = 'A deny from an address with the comment prefix "by Fail2Ban" is taken for a fail2ban ban; only a ban keeps it, from the address it bans. Change the comment.';
        $fromBan = $ban->with(['action' => 'allow', 'scope' => 'host', 'protocol' => 'tcp', 'port' => '80']);
        $this->assertFalse($fromBan->isBan());
        $this->assertSame([], $fromBan->with(['port' => '8080'])->problems($fromBan), 'an allow made from a ban keeps its comment');
        $this->assertSame($kept, $fromBan->with(['action' => 'deny'])->problems($fromBan)['comment'] ?? null);
        $this->assertSame($kept, $fromBan->with(['action' => 'deny', 'source' => '192.0.2.163'])->problems($fromBan)['comment'] ?? null);
        $this->assertSame($kept, $ban->with(['source' => '192.0.2.164'])->problems($ban)['comment'] ?? null);
        $operator = new FirewallRule('allow', 'in', 'tcp', '80', null, null, 'by Fail2Ban test');
        $this->assertSame([], $operator->with(['port' => '8080'])->problems($operator));
        $this->assertSame($kept, $operator->with(['action' => 'deny', 'source' => '192.0.2.165'])->problems($operator)['comment'] ?? null);
        $this->assertSame([], $operator->with(['action' => 'deny'])->problems($operator), 'a deny from any address is not taken for a ban');

        // Only this prefix, as written, is taken for a ban.
        foreach (['Blocked by Fail2Ban by hand', 'by fail2ban, by hand', 'by Fail2ban', 'BY FAIL2BAN', 'fail2ban test', 'by  Fail2Ban'] as $comment) {
            foreach ([$deny, $allow] as $rule) {
                $this->assertSame([], $rule->with(['comment' => $comment])->problems(), $comment);
            }
        }
    }
}
