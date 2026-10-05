<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** /api/firewall/* through the routes, against a provider registered with the factory. */
class FirewallHttpTest extends TestCase
{
    use InMemoryDatabase;

    private object $firewall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);

        $this->firewall = new class implements Firewall {
            /** @var array<string, FirewallRule> */
            public array $rules = [];
            public ?string $refuse = null;
            /** @var list<string> */
            public array $commands = [];

            public function name(): string
            {
                return 'fake';
            }

            public function status(): FirewallStatus
            {
                return new FirewallStatus('fake', true, '1.0', 'deny', 'allow');
            }

            public function rules(): array
            {
                return array_values($this->rules);
            }

            public function rule(string $id): FirewallRule
            {
                return $this->rules[$id] ?? throw FirewallNotFound::rule($id);
            }

            public function addRule(FirewallRule $rule): FirewallRule
            {
                if ($this->refuse !== null) {
                    throw new FirewallException($this->refuse);
                }

                return $this->rules[$rule->id()] = $rule;
            }

            public function updateRule(string $id, FirewallRule $rule): FirewallRule
            {
                $this->rule($id);
                unset($this->rules[$id]);

                return $this->addRule($rule);
            }

            public function deleteRule(string $id): FirewallRule
            {
                $rule = $this->rule($id);
                unset($this->rules[$id]);

                return $rule;
            }

            public function enable(): void
            {
                $this->command('enable');
            }

            public function disable(): void
            {
                $this->command('disable');
            }

            public function reload(): void
            {
                $this->command('reload');
            }

            /** @var list<array{int, ?string, ?string}> */
            public array $logQueries = [];

            public function logs(int $limit = 100, ?string $type = null, ?string $address = null): array
            {
                $this->logQueries[] = [$limit, $type, $address];

                return [new FirewallLogEntry(new \DateTimeImmutable('2026-10-01T15:00:00+00:00'), FirewallLogEntry::BLOCKED, '198.51.100.9', 'in', 'tcp', '23')];
            }

            /** @var array<string, TrustedAddress> */
            public array $trusted = [];

            public function trustedAddresses(): array
            {
                return array_values($this->trusted);
            }

            public function trust(TrustedAddress $address): TrustedAddress
            {
                if ($this->refuse !== null) {
                    throw new FirewallException($this->refuse);
                }

                return $this->trusted[$address->id()] = $address;
            }

            public function untrust(string $id): TrustedAddress
            {
                $entry = $this->trusted[$id] ?? throw FirewallNotFound::trustedAddress($id);
                unset($this->trusted[$id]);

                return $entry;
            }

            private function command(string $name): void
            {
                if ($this->refuse !== null) {
                    throw new FirewallException($this->refuse);
                }
                $this->commands[] = $name;
            }
        };
        $firewall = $this->firewall;
        FirewallFactory::register('fake', fn (): Firewall => $firewall);
        config(['env.FIREWALL_PROVIDER' => 'fake']);
    }

    protected function tearDown(): void
    {
        FirewallFactory::reset();
        parent::tearDown();
    }

    private function hold(array|FirewallRule $rule): FirewallRule
    {
        $r = $rule instanceof FirewallRule ? $rule : FirewallRule::fromArray($rule);

        return $this->firewall->rules[$r->id()] = $r;
    }

    /** @param list<string> $fields */
    private function assertInvalid(TestResponse $response, array $fields): void
    {
        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $response->json('errors'), (string) $response->getContent());
        }
    }

    public function test_status(): void
    {
        $this->getJson('/api/firewall/status')->assertOk()->assertExactJson(['data' => [
            'provider' => 'fake', 'enabled' => true, 'version' => '1.0',
            'default_incoming' => 'deny', 'default_outgoing' => 'allow', 'error' => null,
        ]]);
    }

    public function test_rules_are_listed_with_their_flags(): void
    {
        $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'comment' => 'panelalpha: engine api']);
        $this->hold(['action' => 'deny', 'source' => '203.0.113.7']);

        $rules = $this->getJson('/api/firewall/rules')->assertOk()->json('data');

        $this->assertCount(2, $rules);
        $this->assertTrue($rules[0]['managed']);
        $this->assertFalse($rules[0]['editable']);
        $this->assertSame('203.0.113.7', $rules[1]['source']);
        $this->assertTrue($rules[1]['editable']);
    }

    public function test_host_rules_are_listed_unless_published_ones_are_asked_for(): void
    {
        $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'comment' => 'panelalpha: engine api']);
        $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'comment' => 'panelalpha: engine api', 'scope' => 'published']);
        $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '8080', 'scope' => 'published']);

        $host = $this->getJson('/api/firewall/rules')->assertOk()->json('data');
        $published = $this->getJson('/api/firewall/rules?scope=published')->assertOk()->json('data');

        $this->assertSame([['host', '2011']], array_map(fn (array $r): array => [$r['scope'], $r['port']], $host));
        $this->assertSame([['published', '2011'], ['published', '8080']], array_map(fn (array $r): array => [$r['scope'], $r['port']], $published));
        $this->assertTrue($published[0]['managed']);
        $this->assertInvalid($this->getJson('/api/firewall/rules?scope=everything'), ['scope']);
    }

    public function test_a_deny_is_in_both_scopes_and_listed_once_in_each_list(): void
    {
        $data = $this->postJson('/api/firewall/rules', ['action' => 'deny', 'source' => '203.0.113.7'])->assertOk()->json('data');
        $this->assertSame('both', $data['scope']);
        $only = $this->postJson('/api/firewall/rules', ['action' => 'deny', 'source' => '198.51.100.9', 'scope' => 'published'])->assertOk()->json('data');
        $this->assertSame('published', $only['scope']);

        $host = $this->getJson('/api/firewall/rules')->assertOk()->json('data');
        $published = $this->getJson('/api/firewall/rules?scope=published')->assertOk()->json('data');
        $this->assertSame([$data['id']], array_column($host, 'id'));
        $this->assertSame([$data['id'], $only['id']], array_column($published, 'id'));

        $this->assertInvalid($this->postJson('/api/firewall/rules', ['action' => 'allow', 'port' => '22', 'scope' => 'both']), ['scope']);
        $this->assertInvalid($this->putJson("/api/firewall/rules/{$data['id']}", ['action' => 'allow', 'port' => '22']), ['scope']);
        $this->putJson("/api/firewall/rules/{$data['id']}", ['action' => 'allow', 'port' => '22', 'scope' => 'host'])->assertOk()->assertJsonPath('data.scope', 'host');
    }

    public function test_a_rule_for_published_ports_is_added_edited_and_deleted(): void
    {
        $data = $this->postJson('/api/firewall/rules', ['action' => 'allow', 'scope' => 'published', 'protocol' => 'tcp', 'port' => '8080'])
            ->assertOk()->json('data');
        $this->assertSame(['published', 'in'], [$data['scope'], $data['direction']]);

        $edited = $this->putJson("/api/firewall/rules/{$data['id']}", ['source' => '203.0.113.7'])->assertOk()->json('data');
        $this->assertSame(['published', '203.0.113.7'], [$edited['scope'], $edited['source']]);

        $this->deleteJson("/api/firewall/rules/{$edited['id']}")->assertOk()->assertJsonPath('data.scope', 'published');
        $this->assertSame([], $this->firewall->rules);

        $this->assertInvalid($this->postJson('/api/firewall/rules', ['action' => 'allow', 'scope' => 'published', 'direction' => 'out', 'port' => '25']), ['direction']);
        $this->assertInvalid($this->postJson('/api/firewall/rules', ['action' => 'allow', 'scope' => 'container', 'port' => '25']), ['scope']);
    }

    public function test_a_rule_is_added(): void
    {
        $data = $this->postJson('/api/firewall/rules', [
            'action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7/32', 'comment' => 'office',
        ])->assertOk()->json('data');

        $this->assertSame('203.0.113.7', $data['source']);
        $this->assertSame('in', $data['direction']);
        $this->assertArrayHasKey($data['id'], $this->firewall->rules);
    }

    public function test_what_cannot_be_a_rule_is_refused(): void
    {
        $cases = [
            [[], ['action']],
            [['action' => 'reject', 'port' => '22'], ['action']],
            [['action' => 'allow', 'direction' => 'sideways', 'port' => '22'], ['direction']],
            [['action' => 'allow', 'protocol' => 'icmp', 'port' => '22'], ['protocol']],
            [['action' => 'allow', 'port' => '22;rm'], ['port']],
            [['action' => 'allow'], ['port']],
            [['action' => 'allow', 'port' => '30000:30009'], ['protocol']],
            [['action' => 'allow', 'port' => '80,443'], ['protocol']],
            [['action' => 'deny', 'source' => 'example.com'], ['source']],
            [['action' => 'deny', 'destination' => '1.2.3.4/33'], ['destination']],
            [['action' => 'allow', 'port' => '9', 'comment' => 'panelalpha: mine'], ['comment']],
            [['action' => 'allow', 'port' => '9', 'comment' => "two\nlines"], ['comment']],
        ];
        foreach ($cases as [$body, $fields]) {
            $this->assertInvalid($this->postJson('/api/firewall/rules', $body), $fields);
        }
        $this->assertSame([], $this->firewall->rules);
    }

    public function test_the_providers_refusal_is_a_422_in_its_words(): void
    {
        $this->firewall->refuse = 'Invalid position';

        $response = $this->postJson('/api/firewall/rules', ['action' => 'deny', 'source' => '203.0.113.7']);

        $this->assertInvalid($response, ['rule']);
        $this->assertSame(['Invalid position'], $response->json('errors.rule'));
    }

    public function test_a_rule_is_edited_keeping_what_was_not_sent(): void
    {
        $rule = $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7', 'comment' => 'office']);

        $data = $this->putJson("/api/firewall/rules/{$rule->id()}", ['port' => '2222', 'comment' => null])->assertOk()->json('data');

        $this->assertSame('2222', $data['port']);
        $this->assertSame('203.0.113.7', $data['source']);
        $this->assertNull($data['comment']);
        $this->assertNotSame($rule->id(), $data['id']);
    }

    public function test_an_edit_that_leaves_a_rule_on_nothing_is_refused(): void
    {
        $rule = $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.7']);

        $this->assertInvalid($this->putJson("/api/firewall/rules/{$rule->id()}", ['port' => null, 'source' => null]), ['port']);
        $this->assertInvalid($this->putJson("/api/firewall/rules/{$rule->id()}", ['port' => '1:2', 'protocol' => null]), ['protocol']);
        $this->assertInvalid($this->putJson("/api/firewall/rules/{$rule->id()}", ['action' => 'drop']), ['action']);
        $this->assertArrayHasKey($rule->id(), $this->firewall->rules);
    }

    public function test_engine_rules_and_host_only_rules_are_not_changed(): void
    {
        $managed = $this->hold(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'comment' => 'panelalpha: engine api']);
        $hostOnly = $this->hold(new FirewallRule('limit', 'in', 'tcp', '2222', editable: false, raw: 'limit tcp 2222 any any any in'));

        foreach ([$managed, $hostOnly] as $rule) {
            $this->assertInvalid($this->deleteJson("/api/firewall/rules/{$rule->id()}"), ['id']);
            $this->assertInvalid($this->putJson("/api/firewall/rules/{$rule->id()}", ['comment' => 'x']), ['id']);
        }
        $this->assertCount(2, $this->firewall->rules);
    }

    public function test_a_rule_is_deleted_and_then_not_found(): void
    {
        $rule = $this->hold(['action' => 'deny', 'source' => '203.0.113.7']);

        $this->deleteJson("/api/firewall/rules/{$rule->id()}")->assertOk()->assertJsonPath('data.source', '203.0.113.7');

        $this->deleteJson("/api/firewall/rules/{$rule->id()}")->assertNotFound();
        $this->putJson("/api/firewall/rules/{$rule->id()}", ['comment' => 'x'])->assertNotFound();
    }

    public function test_a_rule_both_ways_needs_the_address_as_source_only(): void
    {
        $data = $this->postJson('/api/firewall/rules', ['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7'])
            ->assertOk()->json('data');
        $this->assertSame('both', $data['direction']);

        $this->assertInvalid($this->postJson('/api/firewall/rules', ['action' => 'deny', 'direction' => 'both', 'port' => '25']), ['source']);
        $this->assertInvalid($this->postJson('/api/firewall/rules', ['action' => 'deny', 'direction' => 'both', 'source' => '203.0.113.7', 'destination' => '198.51.100.1']), ['source']);
        $this->assertInvalid($this->postJson('/api/firewall/rules', ['action' => 'deny', 'direction' => 'sideways', 'source' => '203.0.113.7']), ['direction']);
    }

    public function test_the_log_is_read_with_its_filters(): void
    {
        $this->getJson('/api/firewall/logs')->assertOk()->assertExactJson(['data' => [[
            'time' => '2026-10-01T15:00:00+00:00', 'type' => 'blocked', 'address' => '198.51.100.9',
            'direction' => 'in', 'protocol' => 'tcp', 'port' => '23', 'jail' => null,
        ]]]);
        $this->getJson('/api/firewall/logs?limit=5&type=ban&address=203.0.113.7')->assertOk();

        $this->assertSame([[100, null, null], [5, 'ban', '203.0.113.7']], $this->firewall->logQueries);
        $this->assertInvalid($this->getJson('/api/firewall/logs?limit=0'), ['limit']);
        $this->assertInvalid($this->getJson('/api/firewall/logs?limit=1001'), ['limit']);
        $this->assertInvalid($this->getJson('/api/firewall/logs?type=allowed'), ['type']);
        $this->assertInvalid($this->getJson('/api/firewall/logs?address=example.com'), ['address']);
    }

    public function test_trusted_addresses_are_listed_added_and_removed(): void
    {
        $added = $this->postJson('/api/firewall/trusted', ['address' => '203.0.113.7/32', 'comment' => ' office '])->assertOk()->json('data');
        $this->assertSame(['address' => '203.0.113.7', 'comment' => 'office'], ['address' => $added['address'], 'comment' => $added['comment']]);
        $this->postJson('/api/firewall/trusted', ['address' => '2001:db8::/32'])->assertOk()->assertJsonPath('data.comment', null);

        $this->assertSame(['203.0.113.7', '2001:db8::/32'], array_column($this->getJson('/api/firewall/trusted')->assertOk()->json('data'), 'address'));

        $this->deleteJson("/api/firewall/trusted/{$added['id']}")->assertOk()->assertJsonPath('data.address', '203.0.113.7');
        $this->deleteJson("/api/firewall/trusted/{$added['id']}")->assertNotFound();
        $this->assertCount(1, $this->firewall->trusted);
    }

    public function test_only_an_address_can_be_trusted(): void
    {
        $this->assertInvalid($this->postJson('/api/firewall/trusted', []), ['address']);
        $this->assertInvalid($this->postJson('/api/firewall/trusted', ['address' => 'example.com']), ['address']);
        $this->assertInvalid($this->postJson('/api/firewall/trusted', ['address' => '203.0.113.7', 'comment' => "a\nb"]), ['comment']);

        $this->firewall->refuse = 'fail2ban did not take the new list';
        $this->assertInvalid($this->postJson('/api/firewall/trusted', ['address' => '203.0.113.7']), ['rule']);
        $this->assertSame([], $this->firewall->trusted);
    }

    public function test_enable_disable_and_reload(): void
    {
        $this->putJson('/api/firewall/enable')->assertOk()->assertExactJson(['data' => '']);
        $this->putJson('/api/firewall/disable')->assertOk();
        $this->putJson('/api/firewall/reload')->assertOk();
        $this->assertSame(['enable', 'disable', 'reload'], $this->firewall->commands);
    }

    public function test_a_failed_command_is_a_502_with_the_firewalls_message(): void
    {
        $this->firewall->refuse = 'problem running ufw-init';

        foreach (['enable', 'disable', 'reload'] as $command) {
            $this->putJson("/api/firewall/{$command}")->assertStatus(502)->assertExactJson(['message' => 'problem running ufw-init']);
        }
    }
}
