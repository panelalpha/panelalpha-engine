<?php

namespace Tests\Unit\System\Services;

use App\Models\ProxyRule;
use App\System\Services\Webserver\ProxyRuleServerName;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A domain's own vhost answers for its name and aliases, and serves only a container project's
 * rule named after the domain itself, on 443 only while the domain has SSL: the warning follows that.
 */
class ProxyRuleDefaultCertificateWarningTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $shop = $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');
        $shop->addAlias('www.shop.test');
        $shop->save();
        $carol = $this->makeMainDomain($this->makeUser('carol'), 'carol.test');
        $carol->addAlias('www.carol.test');
        $carol->save();
    }

    public function test_a_container_domain_with_ssl_takes_the_rule_with_its_certificate(): void
    {
        $this->assertNull(ProxyRuleServerName::defaultCertificateWarning($this->rule('shop.test')));
        $this->assertNull(ProxyRuleServerName::defaultCertificateWarning($this->rule('SHOP.test')));
    }

    public function test_a_rule_named_after_an_alias_is_never_served(): void
    {
        $this->assertSame(
            'WWW.shop.test is an alias of shop.test, whose own site answers for it, so this rule on port 443 is never '
            .'served. Name the rule shop.test: a rule for the domain serves its aliases too.',
            ProxyRuleServerName::defaultCertificateWarning($this->rule('WWW.shop.test'))
        );
    }

    public function test_a_rule_named_after_a_traditional_php_projects_domain_or_alias_is_never_served(): void
    {
        foreach (['carol.test', 'www.carol.test'] as $name) {
            $this->assertSame(
                "{$name} belongs to the traditional PHP project carol, whose own site answers for it, so this rule on port 443 "
                .'is never served.',
                ProxyRuleServerName::defaultCertificateWarning($this->rule($name))
            );
        }
    }

    public function test_a_container_domain_without_ssl_has_no_server_on_443_for_the_rule(): void
    {
        $plain = $this->makeMainDomain($this->makeUser('dave', ['template' => 'dind']), 'plain.test');
        $plain->setDetails(['ssl_disabled' => true]);
        $plain->save();

        $this->assertSame(
            'plain.test was added without SSL, so its site has no server on port 443 and this rule is not served: '
            ."clients get the host's default self-signed certificate and will warn.",
            ProxyRuleServerName::defaultCertificateWarning($this->rule('plain.test'))
        );
    }

    public function test_only_a_live_http_rule_on_443_is_warned_about(): void
    {
        $this->assertNull(ProxyRuleServerName::defaultCertificateWarning($this->rule('www.shop.test', 80)));
        $this->assertNull(ProxyRuleServerName::defaultCertificateWarning($this->rule('carol.test', 443, false)));
    }

    private function rule(string $serverName, int $port = 443, bool $enabled = true): ProxyRule
    {
        return new ProxyRule([
            'owner_scope' => 'system',
            'enabled' => $enabled,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => $port,
            'server_name' => $serverName,
            'upstream_host' => TestNetwork::private(5),
            'upstream_port' => 8080,
        ]);
    }
}
