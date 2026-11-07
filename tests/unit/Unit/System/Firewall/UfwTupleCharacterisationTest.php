<?php

namespace Tests\Unit\System\Firewall;

use Tests\Support\TestNetwork;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\Ufw\UfwRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every field {@see UfwRules::parseTuple()} reads from one `### tuple ###` line.
 */
class UfwTupleCharacterisationTest extends TestCase
{
    /**
     * @return array<string, array{string, ?array<string, mixed>}>
     */
    public static function tuples(): array
    {
        $rule = static fn (array $fields): array => array_merge([
            'action' => 'allow', 'direction' => 'in', 'protocol' => null, 'port' => null, 'source' => null,
            'destination' => null, 'comment' => null, 'editable' => true, 'raw' => null, 'scope' => FirewallRule::HOST,
        ], $fields);

        return [
            'not a tuple' => ['-A ufw-user-input -p tcp --dport 22 -j ACCEPT', null],
            'too few fields' => ['### tuple ### allow tcp 22 '.TestNetwork::ANY_CIDR.' any', null],
            'eight fields' => ['### tuple ### allow tcp 22 '.TestNetwork::ANY_CIDR.' any '.TestNetwork::ANY_CIDR.' x in', null],
            'allow any' => ['### tuple ### allow tcp 22 '.TestNetwork::ANY_CIDR.' any '.TestNetwork::ANY_CIDR.' in', $rule(['protocol' => 'tcp', 'port' => '22'])],
            'deny out with a comment' => [
                '### tuple ### deny any any '.TestNetwork::DOCUMENTATION_3.' any ::/0 out comment='.bin2hex('blocked host'),
                $rule(['action' => 'deny', 'direction' => 'out', 'destination' => TestNetwork::DOCUMENTATION_3, 'comment' => 'blocked host']),
            ],
            'a comment that is not hex' => [
                '### tuple ### allow udp 53 any any '.TestNetwork::PRIVATE_10.' in comment=zz',
                $rule(['protocol' => 'udp', 'port' => '53', 'source' => TestNetwork::PRIVATE_10]),
            ],
            'a source port' => [
                '### tuple ### allow tcp 80 any 1024 any in',
                $rule(['protocol' => 'tcp', 'port' => '80', 'editable' => false, 'raw' => 'allow tcp 80 any 1024 any in']),
            ],
            'limit' => [
                '### tuple ### limit tcp 22 '.TestNetwork::ANY_CIDR.' any '.TestNetwork::ANY_CIDR.' in',
                $rule(['action' => 'limit', 'protocol' => 'tcp', 'port' => '22', 'editable' => false, 'raw' => 'limit tcp 22 any any any in']),
            ],
            'apps' => [
                '### tuple ### allow any 22 '.TestNetwork::ANY_CIDR.' any '.TestNetwork::ANY_CIDR.' OpenSSH - in',
                $rule(['port' => '22', 'editable' => false, 'raw' => 'allow any 22 any any any OpenSSH - in']),
            ],
            'route in' => [
                '### tuple ### route:deny tcp 8080 any any '.TestNetwork::DOCUMENTATION_2.' in',
                $rule(['action' => 'deny', 'protocol' => 'tcp', 'port' => '8080', 'source' => TestNetwork::DOCUMENTATION_2, 'scope' => FirewallRule::PUBLISHED]),
            ],
            'route out' => [
                '### tuple ### route:allow any any any any any out',
                $rule(['direction' => 'out', 'editable' => false, 'raw' => 'route:allow any any any any any out', 'scope' => FirewallRule::PUBLISHED]),
            ],
            'an interface' => [
                '### tuple ### allow tcp 443 any any any in_eth0',
                $rule(['protocol' => 'tcp', 'port' => '443', 'editable' => false, 'raw' => 'allow tcp 443 any any any in_eth0']),
            ],
        ];
    }

    /** @param ?array<string, mixed> $expected */
    #[DataProvider('tuples')]
    public function test_a_tuple_line_reads_as(string $line, ?array $expected): void
    {
        $rule = UfwRules::parseTuple($line);

        if ($expected === null) {
            $this->assertNull($rule);

            return;
        }
        $this->assertNotNull($rule);
        $this->assertSame($expected, [
            'action' => $rule->action, 'direction' => $rule->direction, 'protocol' => $rule->protocol, 'port' => $rule->port,
            'source' => $rule->source, 'destination' => $rule->destination, 'comment' => $rule->comment,
            'editable' => $rule->editable, 'raw' => $rule->raw, 'scope' => $rule->scope,
        ]);
    }
}
