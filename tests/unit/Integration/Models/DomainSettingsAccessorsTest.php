<?php

namespace Tests\Integration\Models;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A domain's redirect, PHP version, aliases and vhost names, as stored in its details. */
class DomainSettingsAccessorsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_domain_without_its_project_says_which_domain(): void
    {
        $domain = new Domain(['domain' => 'lost.example.test']);

        $this->expectExceptionMessage('User related to domain lost.example.test not found in database.');

        $domain->getUser();
    }

    public function test_a_redirect_is_only_reported_while_enabled_and_with_a_target(): void
    {
        $domain = new Domain(['domain' => 'shop.example.test']);
        $this->assertNull($domain->getRedirectUrl());

        $domain->setRedirectUrl('https://new.example.test/');
        $this->assertTrue($domain->redirectEnabled());
        $this->assertSame('https://new.example.test/', $domain->getRedirectUrl());

        $domain->setDetails(['redirect_url' => '']);
        $this->assertNull($domain->getRedirectUrl());

        $domain->setRedirectUrl('https://again.example.test/');
        $domain->disableRedirect();
        $this->assertFalse($domain->redirectEnabled());
        $this->assertNull($domain->getRedirectUrl());
        $this->assertNull($domain->getDetails()['redirect_url']);
    }

    public function test_a_stored_php_version_and_document_root_are_read_back(): void
    {
        $domain = new Domain(['domain' => 'php.example.test']);

        $domain->setPhpVersion('8.3');
        $domain->setDocumentRoot('/php.example.test/web');

        $this->assertSame('8.3', $domain->getPhpVersion());
        $this->assertSame('/php.example.test/web', $domain->getDocumentRoot());
        $this->assertSame(['/php.example.test', '/php.example.test/web'], $domain->directoriesKeptOnDelete());
    }

    public function test_forcing_https_is_stored_and_can_be_turned_off(): void
    {
        $domain = new Domain(['domain' => 'tls.example.test']);
        $this->assertFalse($domain->forceHttpsRedirectEnabled());

        $domain->setForceHttpsRedirect(true);
        $this->assertTrue($domain->forceHttpsRedirectEnabled());

        $domain->setForceHttpsRedirect(false);
        $this->assertFalse($domain->forceHttpsRedirectEnabled());
    }

    public function test_a_tunnel_hostname_is_not_available_as_an_alias(): void
    {
        $user = $this->makeUser('aliasowner');
        $domain = $this->makeMainDomain($user, 'aliasowner.example.test');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $user->id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'app.aliasowner.example.test']);

        $this->assertFalse($domain->isAliasAvailable('app.aliasowner.example.test'));
        $this->assertTrue($domain->isAliasAvailable('free.aliasowner.example.test'));
    }

    public function test_the_default_ip_domain_also_answers_on_the_default_address(): void
    {
        Setting::setRuntimeSettings(['vhost-default-ip-domain' => 'ipdomain.example.test', 'default_ipv4' => TestNetwork::public(9)]);
        $user = $this->makeUser('ipdomain');
        $domain = $this->makeMainDomain($user, 'ipdomain.example.test');
        $domain->setDetails(['aliases' => ['www.ipdomain.example.test']]);

        $this->assertSame(['www.ipdomain.example.test', TestNetwork::public(9)], $domain->getVhostAltNames());
    }
}
