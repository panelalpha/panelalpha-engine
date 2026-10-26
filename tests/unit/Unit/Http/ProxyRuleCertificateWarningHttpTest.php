<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * Only a rule named after a domain or alias is served with that domain's certificate;
 * any other HTTP rule on 443, a wildcard included, gets the host's default one and says so.
 */
class ProxyRuleCertificateWarningHttpTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private const WARNING = 'No domain or alias on this engine is named *.shop.test, so this rule on port 443 is served with the host\'s '
        . 'default self-signed certificate and clients will warn. Name one of this engine\'s domains or aliases to use its certificate.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->withoutMiddleware(Authenticate::class);
        $alice = $this->makeMainDomain($this->makeUser('alice'), 'shop.test');
        $alice->addAlias('www.shop.test');
        $alice->save();
    }

    /** @return array<string, mixed> */
    private function rule(?string $serverName, int $port = 443, array $extra = []): array
    {
        return $extra + ['owner_scope' => 'system', 'transport' => 'http', 'listen_port' => $port,
            'server_name' => $serverName, 'upstream_host' => '10.0.0.5', 'upstream_port' => 8080];
    }

    public function test_a_wildcard_rule_on_443_is_created_with_a_warning(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('*.shop.test'))
            ->assertCreated()
            ->assertJsonPath('data.server_name', '*.shop.test')
            ->assertJsonPath('warnings', [self::WARNING]);
    }

    public function test_a_rule_without_a_name_on_443_is_warned_about_too(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule(null))
            ->assertCreated()
            ->assertJsonPath('warnings.0', str_replace('*.shop.test', '_', self::WARNING));
    }

    public function test_a_rule_a_certificate_covers_or_that_is_not_on_443_has_no_warning(): void
    {
        foreach ([$this->rule('shop.test'), $this->rule('WWW.shop.test', 443, ['listen_ip' => '10.0.0.7']),
            $this->rule('*.shop.test', 8443), $this->rule('*.other.test', 443, ['enabled' => false])] as $rule) {
            $this->postJson('/api/proxy-rules', $rule)->assertCreated()->assertJsonMissingPath('warnings');
        }
    }

    public function test_switching_a_wildcard_rule_on_443_back_on_warns(): void
    {
        $id = $this->postJson('/api/proxy-rules', $this->rule('*.shop.test', 443, ['enabled' => false]))->assertCreated()->json('data.id');

        $this->putJson("/api/proxy-rules/{$id}", ['enabled' => true])->assertOk()->assertJsonPath('warnings', [self::WARNING]);
        $this->putJson("/api/proxy-rules/{$id}", ['enabled' => false])->assertOk()->assertJsonMissingPath('warnings');
        $this->assertFalse((bool) ProxyRule::query()->sole()->enabled);
    }
}
