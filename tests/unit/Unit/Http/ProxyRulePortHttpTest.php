<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * POST and PUT /proxy-rules refuse a listen port the engine or something
 * else on the host already binds: the webserver could not apply it, and its
 * next restart would fail for every site. MCP goes through the same calls.
 */
class ProxyRulePortHttpTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->withoutMiddleware(Authenticate::class);
        // A project may answer for its own domain; a system rule for any.
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');
        $any = TestNetwork::ANY;
        $loopback = TestNetwork::LOOPBACK;
        $this->hostListens("LISTEN 0 4096 {$any}:2011 {$any}:*\nLISTEN 0 80 {$loopback}:3306 {$any}:*");
    }

    /** @return array<string, mixed> */
    private function rule(string $transport, int $port, array $extra = []): array
    {
        return ['owner_scope' => 'system', 'transport' => $transport, 'listen_port' => $port,
            'upstream_host' => 'app', 'upstream_port' => 3000] + $extra;
    }

    public function test_a_reserved_or_bound_port_is_refused_at_create(): void
    {
        foreach ([
            [$this->rule('tcp', 22), 'Port 22 is reserved by the engine.'],
            [$this->rule('udp', 2011), 'Port 2011 is reserved by the engine.'],
            [$this->rule('http', 3306), 'Port 3306 is already in use on this host.'],
            [$this->rule('tcp', 443), "Port 443 is the webserver's own; only an http rule can listen on it."],
        ] as [$body, $message]) {
            $this->postJson('/api/proxy-rules', $body)
                ->assertStatus(422)
                ->assertJsonPath('errors.listen_port.0', $message);
        }

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }

    public function test_a_free_port_and_the_webservers_own_for_http_are_accepted(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('http', 80, ['server_name' => 'shop.test']))->assertSuccessful();
        $this->postJson('/api/proxy-rules', $this->rule('http', 8081))->assertSuccessful();
        $this->postJson('/api/proxy-rules', $this->rule('tcp', 25212))->assertSuccessful();

        $this->assertSame(3, ProxyRule::query()->count());
        $this->assertWebserverApplied(3);
    }

    public function test_a_duplicate_rule_is_refused_on_its_listen_port(): void
    {
        $message = 'A rule with the same transport, port, and server_name already exists';
        foreach ([$this->rule('tcp', 17092), $this->rule('http', 8081, ['server_name' => 'shop.test'])] as $body) {
            $this->postJson('/api/proxy-rules', $body)->assertSuccessful();
            $this->postJson('/api/proxy-rules', $body)
                ->assertStatus(422)
                ->assertJsonPath('errors.listen_port.0', $message)
                ->assertJsonPath('problems.0.field', 'listen_port')
                ->assertJsonPath('problems.0.code', 'listen_port_invalid')
                ->assertJsonPath('problems.0.message', $message);
        }

        $this->assertSame(2, ProxyRule::query()->count());
        $this->assertWebserverApplied(2);
    }

    /** @return array<string, mixed> */
    private function httpRule(string $name, int $port, string $scope, string $upstream, string $listenIp, bool $enabled = true): array
    {
        return [
            'owner_scope' => $scope,
            'username' => $scope === 'user' ? 'alice' : null,
            'transport' => 'http',
            'listen_port' => $port,
            'listen_ip' => $listenIp,
            'server_name' => $name,
            'upstream_host' => $upstream,
            'upstream_port' => 3000,
            'enabled' => $enabled,
        ];
    }

    /** Two HTTP rules on one name and port: the vhost keys one entry per port, so one is refused. */
    public function test_an_http_duplicate_on_another_listen_ip_or_owner_scope_is_refused(): void
    {
        $this->postJson('/api/proxy-rules', $this->httpRule('shop.test', 8081, 'user', 'alice', TestNetwork::private(5)))->assertSuccessful();
        // Same name and port, different listen IP.
        $this->postJson('/api/proxy-rules', $this->httpRule('shop.test', 8081, 'user', 'alice', '*'))
            ->assertStatus(422)
            ->assertJsonPath('errors.listen_port.0', 'A rule with the same transport, port, and server_name already exists');
        // Same name and port, a system rule beside a user rule.
        $this->postJson('/api/proxy-rules', $this->httpRule('shop.test', 8081, 'system', TestNetwork::private(5), '*'))
            ->assertStatus(422);

        $this->assertSame(1, ProxyRule::query()->count());
    }

    /** Re-enabling a disabled HTTP duplicate runs the same check a fresh rule gets. */
    public function test_re_enabling_a_disabled_http_duplicate_is_refused(): void
    {
        $this->postJson('/api/proxy-rules', $this->httpRule('shop.test', 8081, 'system', TestNetwork::private(5), '*'))->assertSuccessful();
        $this->postJson('/api/proxy-rules', $this->httpRule('shop.test', 8081, 'user', 'alice', '*', false))->assertSuccessful();

        /** @var ProxyRule $duplicate */
        $duplicate = ProxyRule::query()->where('owner_scope', 'user')->sole();
        $this->putJson("/api/proxy-rules/{$duplicate->id}", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.listen_port.0', 'A rule with the same transport, port, and server_name already exists');

        $this->assertFalse($duplicate->fresh()->enabled);
    }

    /** stream.conf renders no server name: nginx would serve one of two tcp rules on one address and port. */
    public function test_re_enabling_a_disabled_stream_duplicate_is_refused(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('tcp', 17092))->assertSuccessful();
        $this->postJson('/api/proxy-rules', $this->rule('tcp', 17092, ['enabled' => false]))->assertSuccessful();

        /** @var ProxyRule $duplicate */
        $duplicate = ProxyRule::query()->where('enabled', false)->sole();
        $this->putJson("/api/proxy-rules/{$duplicate->id}", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.listen_port.0', 'A rule with the same transport, port, and server_name already exists');

        $this->assertFalse($duplicate->fresh()->enabled);
        $this->assertWebserverApplied(2);
    }

    public function test_a_disabled_rule_is_stored_whatever_its_port_and_checked_when_enabled(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('tcp', 3306, ['enabled' => false]))->assertSuccessful();
        $id = ProxyRule::query()->sole()->id;

        $this->putJson("/api/proxy-rules/{$id}", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.listen_port.0', 'Port 3306 is already in use on this host.');
        $this->assertFalse(ProxyRule::query()->sole()->enabled);
    }

    public function test_an_enabled_rule_keeps_its_own_port_on_update(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('tcp', 25212))->assertSuccessful();
        $id = ProxyRule::query()->sole()->id;
        // Now nginx binds it, for this very rule.
        $this->hostListens('LISTEN 0 511 '.TestNetwork::ANY.':25212 '.TestNetwork::ANY.':*');

        $this->putJson("/api/proxy-rules/{$id}", ['upstream_port' => 3001, 'enabled' => true])->assertOk();
        $this->assertSame(3001, ProxyRule::query()->sole()->upstream_port);
    }
}
