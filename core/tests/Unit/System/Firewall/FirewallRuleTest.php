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
}
