<?php

namespace Tests\Unit\Models;

use App\Models\ProxyRule;
use App\System\Services\Webserver\ProxyRuleUpstream;
use Illuminate\Support\Facades\Log;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Older panels stored a project's rule without its username, and such a rule is
 * no longer served. The upgrade gives it the project its upstream names.
 */
class ProxyRuleOrphanAdoptionTest extends TestCase
{
    use InMemoryDatabase;

    public function test_a_rule_to_a_project_is_adopted_and_any_other_is_left_and_logged(): void
    {
        $this->bootInMemoryDatabase();
        $this->makeUser('alice');
        $adopted = $this->orphan('alice', 17002);
        $foreign = $this->orphan('172.25.0.2', 17003);
        $system = ProxyRule::query()->create($this->row('10.0.0.5', 17004) + ['owner_scope' => 'system']);
        Log::spy();

        (require base_path('database/migrations/2026_10_05_000000_adopt_orphan_project_proxy_rules.php'))->up();

        $this->assertSame('alice', $adopted->fresh()->username);
        $this->assertTrue(ProxyRuleUpstream::serves($adopted->fresh()));
        $this->assertNull($foreign->fresh()->username);
        $this->assertNull($system->fresh()->username);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, "Proxy rule {$foreign->id} has no project")
        );
    }

    private function orphan(string $upstream, int $port): ProxyRule
    {
        /** @var ProxyRule */
        return ProxyRule::query()->create($this->row($upstream, $port) + ['owner_scope' => 'user']);
    }

    /** @return array<string, mixed> */
    private function row(string $upstream, int $port): array
    {
        return ['username' => null, 'enabled' => true, 'transport' => 'tcp', 'listen_ip' => '*', 'listen_port' => $port,
            'upstream_host' => $upstream, 'upstream_port' => 8080, 'is_generated' => false];
    }
}
