<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * A rule named after a container project's domain is served with that domain's certificate; any
 * other HTTP rule on 443, a wildcard included, that no trusted certificate on the server covers gets
 * the host's default one and says so.
 */
class ProxyRuleCertificateWarningHttpTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private const WARNING = 'No domain or alias on this engine is named *.shop.test and no trusted certificate on the server covers it, so this rule '
        . 'on port 443 is served with the host\'s default self-signed certificate and clients will warn. Name a container '
        . 'project\'s domain, or put a publicly trusted certificate for *.shop.test in /etc/letsencrypt/live on the server.';

    private const CATCH_ALL_WARNING = 'No domain or alias on this engine is named _, so this rule on port 443 is served with the host\'s '
        . 'default self-signed certificate and clients will warn. Name a container project\'s domain to use its certificate.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->withoutMiddleware(Authenticate::class);
        $alice = $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');
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
            ->assertJsonPath('warnings.0', self::CATCH_ALL_WARNING);
    }

    public function test_a_rule_a_certificate_covers_or_that_is_not_on_443_has_no_warning(): void
    {
        foreach ([$this->rule('shop.test'), $this->rule('*.shop.test', 8443), $this->rule('*.other.test', 443, ['enabled' => false])] as $rule) {
            $this->postJson('/api/proxy-rules', $rule)->assertCreated()->assertJsonMissingPath('warnings');
        }
    }

    /** Without SSL the domain's vhost has no 443 server to render the rule into. */
    public function test_a_rule_named_after_a_container_domain_without_ssl_gets_the_default_certificate(): void
    {
        $plain = $this->makeMainDomain($this->makeUser('dave', ['template' => 'dind']), 'plain.test');
        $plain->setDetails(['ssl_disabled' => true]);
        $plain->save();

        $this->postJson('/api/proxy-rules', $this->rule('plain.test'))
            ->assertCreated()
            ->assertJsonPath('warnings', ['plain.test was added without SSL, so its site has no server on port 443 and this '
                . 'rule is not served: clients get the host\'s default self-signed certificate and will warn.']);
    }

    public function test_switching_a_wildcard_rule_on_443_back_on_warns(): void
    {
        $id = $this->postJson('/api/proxy-rules', $this->rule('*.shop.test', 443, ['enabled' => false]))->assertCreated()->json('data.id');

        $this->putJson("/api/proxy-rules/{$id}", ['enabled' => true])->assertOk()->assertJsonPath('warnings', [self::WARNING]);
        $this->putJson("/api/proxy-rules/{$id}", ['enabled' => false])->assertOk()->assertJsonMissingPath('warnings');
        $this->assertFalse((bool) ProxyRule::query()->sole()->enabled);
    }
}
