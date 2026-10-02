<?php

namespace Tests\Unit\System\Firewall;

use App\System\Firewall\FirewallRule;
use App\System\Firewall\Ufw\UfwRules;
use PHPUnit\Framework\TestCase;

/** /etc/ufw/user.rules as ufw 0.36 writes it. */
class UfwRulesTest extends TestCase
{
    private const V4 = <<<'RULES'
*filter
:ufw-user-input - [0:0]

### RULES ###

### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in comment=6279204661696c3242616e206166746572203520617474656d70747320616761696e73742073736864
-A ufw-user-input -s 203.0.113.7 -j DROP

### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in comment=70616e656c616c7068613a20737368
-A ufw-user-input -p tcp --dport 22 -j ACCEPT

### tuple ### allow tcp 30000:30009 0.0.0.0/0 any 0.0.0.0/0 in comment=70616e656c616c7068613a206674702070617373697665
-A ufw-user-input -p tcp -m multiport --dports 30000:30009 -j ACCEPT

### tuple ### allow udp 53 0.0.0.0/0 any 198.51.100.0/24 in
### tuple ### allow tcp 80 0.0.0.0/0 any 0.0.0.0/0 in_eth0
### tuple ### limit tcp 2222 0.0.0.0/0 any 0.0.0.0/0 in
### tuple ### allow_log tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in
### tuple ### route:allow any any 10.0.0.0/8 any 0.0.0.0/0 in
### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 OpenSSH - in
### tuple ### deny tcp 3306 1.2.3.4 any 0.0.0.0/0 out
garbage line
### tuple ### too short
RULES;

    private const V6 = <<<'RULES'
### tuple ### allow tcp 22 ::/0 any ::/0 in comment=70616e656c616c7068613a20737368
### tuple ### deny any any ::/0 any 2001:db8::/32 in
RULES;

    /** @return array<string, FirewallRule> */
    private function byRaw(): array
    {
        $out = [];
        foreach (UfwRules::parse(self::V4, self::V6) as $rule) {
            $out[$rule->raw ?? implode(' ', [$rule->action, $rule->direction, $rule->protocol ?? '*', $rule->port ?? '*', $rule->source ?? '*', $rule->destination ?? '*'])] = $rule;
        }

        return $out;
    }

    public function test_a_rule_written_to_both_files_is_one_rule(): void
    {
        $ssh = array_values(array_filter(
            UfwRules::parse(self::V4, self::V6),
            fn (FirewallRule $r): bool => $r->port === '22' && $r->editable
        ));

        $this->assertCount(1, $ssh);
        $this->assertNull($ssh[0]->source);
        $this->assertSame('panelalpha: ssh', $ssh[0]->comment);
        $this->assertTrue($ssh[0]->managed());
    }

    public function test_fields_any_and_comments_are_read(): void
    {
        $rules = $this->byRaw();

        $ban = $rules['deny in * * 203.0.113.7 *'];
        $this->assertSame('by Fail2Ban after 5 attempts against sshd', $ban->comment);
        $this->assertTrue($ban->editable);
        $this->assertFalse($ban->managed());

        $dns = $rules['allow in udp 53 198.51.100.0/24 *'];
        $this->assertNull($dns->comment);

        $out = $rules['deny out tcp 3306 * 1.2.3.4'];
        $this->assertSame('1.2.3.4', $out->destination);

        $this->assertArrayHasKey('deny in * * 2001:db8::/32 *', $rules);
    }

    public function test_rules_with_options_the_model_lacks_are_listed_but_not_editable(): void
    {
        $raw = array_map(fn (FirewallRule $r): ?string => $r->raw, array_filter(
            UfwRules::parse(self::V4, self::V6),
            fn (FirewallRule $r): bool => !$r->editable
        ));

        $this->assertEqualsCanonicalizing([
            'allow tcp 80 any any any in_eth0',
            'limit tcp 2222 any any any in',
            'allow_log tcp 8080 any any any in',
            'route:allow any any 10.0.0.0/8 any any in',
            'allow tcp 22 any any any OpenSSH - in',
        ], array_values($raw));
    }

    public function test_the_order_is_the_order_ufw_evaluates(): void
    {
        $rules = UfwRules::parse(self::V4, self::V6);

        $this->assertSame('203.0.113.7', $rules[0]->source);
        $this->assertSame('2001:db8::/32', $rules[count($rules) - 1]->source);
    }

    public function test_the_spec_is_what_follows_ufw(): void
    {
        $rule = FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '2011', 'source' => '203.0.113.0/24', 'comment' => "it's me"]);

        $this->assertSame(
            ['deny', 'in', 'proto', 'tcp', 'from', '203.0.113.0/24', 'to', 'any', 'port', '2011', 'comment', "it's me"],
            UfwRules::spec($rule)
        );
        $this->assertSame(
            ['deny', 'in', 'proto', 'tcp', 'from', '203.0.113.0/24', 'to', 'any', 'port', '2011'],
            UfwRules::spec($rule, withComment: false)
        );
        $this->assertSame(
            ['allow', 'out', 'from', 'any', 'to', '1.2.3.4'],
            UfwRules::spec(FirewallRule::fromArray(['action' => 'allow', 'direction' => 'out', 'destination' => '1.2.3.4']))
        );
    }

    public function test_an_inbound_and_outbound_pair_is_one_rule_both_ways(): void
    {
        $c = 'comment=' . bin2hex('spam');
        $rules = UfwRules::parse(implode("\n", [
            "### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in {$c}",
            '### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in',
            "### tuple ### deny any any 203.0.113.7 any 0.0.0.0/0 out {$c}",
            // Same address, but a different comment: not the same rule.
            '### tuple ### deny any any 0.0.0.0/0 any 198.51.100.1 in',
            '### tuple ### deny any any 198.51.100.1 any 0.0.0.0/0 out comment=' . bin2hex('other'),
        ]));

        $this->assertCount(4, $rules);
        $both = $rules[0];
        $this->assertSame([FirewallRule::BOTH, '203.0.113.7', null, 'spam'], [$both->direction, $both->source, $both->destination, $both->comment]);
        $this->assertSame(FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7'])->id(), $both->id());
        $this->assertSame(['in', 'out'], [$rules[2]->direction, $rules[3]->direction]);
    }

    /** A deny is prepended half by half, so ufw keeps the outbound half above the inbound one. */
    public function test_the_pair_is_found_whichever_half_comes_first(): void
    {
        $c = 'comment=' . bin2hex('probe both');
        $rules = UfwRules::parse(implode("\n", [
            "### tuple ### deny any any 203.0.113.99 any 0.0.0.0/0 out {$c}",
            '### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in',
            "### tuple ### deny any any 0.0.0.0/0 any 203.0.113.99 in {$c}",
        ]));

        $this->assertCount(2, $rules);
        $this->assertSame([FirewallRule::BOTH, '203.0.113.99', 'probe both'], [$rules[0]->direction, $rules[0]->source, $rules[0]->comment]);
        $this->assertSame('22', $rules[1]->port);
    }

    public function test_a_rule_both_ways_is_written_as_its_two_halves(): void
    {
        $rule = FirewallRule::fromArray(['action' => 'deny', 'direction' => 'both', 'protocol' => 'tcp', 'port' => '25', 'source' => '203.0.113.7', 'comment' => 'spam']);

        $this->assertSame([
            ['deny', 'in', 'proto', 'tcp', 'from', '203.0.113.7', 'to', 'any', 'port', '25', 'comment', 'spam'],
            ['deny', 'out', 'proto', 'tcp', 'from', 'any', 'to', '203.0.113.7', 'port', '25', 'comment', 'spam'],
        ], UfwRules::specs($rule));
        $this->assertSame([UfwRules::spec($rule->with(['direction' => 'in']))], UfwRules::specs($rule->with(['direction' => 'in'])));
    }

    public function test_a_parsed_rule_keeps_the_id_it_was_added_under(): void
    {
        $added = FirewallRule::fromArray(['action' => 'deny', 'source' => '203.0.113.7/32']);
        $listed = UfwRules::parseTuple('### tuple ### deny any any 0.0.0.0/0 any 203.0.113.7 in');

        $this->assertNotNull($listed);
        $this->assertSame($added->id(), $listed->id());
    }
}
