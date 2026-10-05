<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
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
        $this->hostListens("LISTEN 0 4096 0.0.0.0:2011 0.0.0.0:*\nLISTEN 0 80 127.0.0.1:3306 0.0.0.0:*");
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
        $this->hostListens('LISTEN 0 511 0.0.0.0:25212 0.0.0.0:*');

        $this->putJson("/api/proxy-rules/{$id}", ['upstream_port' => 3001, 'enabled' => true])->assertOk();
        $this->assertSame(3001, ProxyRule::query()->sole()->upstream_port);
    }
}
