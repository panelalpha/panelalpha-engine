<?php

namespace Tests\Integration\Models;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Each domain of a container project gets the generated :80/:443 pair to its app, and a removed domain loses it.
 */
class ProxyRuleGeneratedPairsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('shop');
    }

    public function test_each_domain_gets_its_own_pair_and_a_gone_one_loses_it(): void
    {
        ProxyRule::syncGeneratedHttpPairs('shop', ['old.example.test'], 3000);
        ProxyRule::syncGeneratedHttpPairs('shop', ['shop.example.test', 'shop.example.com'], 8000);

        $rules = ProxyRule::query()->orderBy('server_name')->orderBy('listen_port')->get()
            ->map(static fn (ProxyRule $rule): string => "{$rule->server_name}:{$rule->listen_port}->{$rule->upstream_port}")
            ->all();
        $this->assertSame([
            'shop.example.com:80->8000',
            'shop.example.com:443->8000',
            'shop.example.test:80->8000',
            'shop.example.test:443->8000',
        ], $rules);
        $descriptions = ProxyRule::query()->where('listen_port', 443)->get()->mapWithKeys(static fn (ProxyRule $rule): array => [$rule->server_name => $rule->metadata['description'] ?? '']);
        $this->assertStringStartsWith('Primary', $descriptions['shop.example.test']);
        $this->assertStringStartsWith('Additional', $descriptions['shop.example.com']);
    }

    public function test_the_served_upstream_is_what_a_new_domain_is_routed_to(): void
    {
        $this->assertNull(ProxyRule::generatedUpstreamOf('shop'));

        ProxyRule::syncGeneratedHttpPairs('shop', ['shop.example.test'], 8443, 'https');

        $this->assertSame(['port' => 8443, 'protocol' => 'https'], ProxyRule::generatedUpstreamOf('shop'));
        $this->assertNull(ProxyRule::generatedUpstreamOf('other'));
    }
}
