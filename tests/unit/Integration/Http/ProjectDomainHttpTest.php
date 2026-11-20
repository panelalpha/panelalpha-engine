<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Limits\ResourceLimit;
use App\Models\Domain;
use App\Models\User;
use App\System\Services\Webserver;
use Tests\Integration\Ssl\TestCertificates;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/** /projects/{project}/domains: reading a project's domains, and every refusal made before the host is touched. */
class ProjectDomainHttpTest extends TestCase
{
    use InMemoryDatabase;

    private ScriptedBroker $broker;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->answer('compose.serviceContainers', json_encode(['Labels' => 'com.panelalpha.webserver=nginx-proxy']));
        $this->alice = $this->makeUser('alice', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001], 'shop.test');
        $this->makeMainDomain($this->alice, 'shop.test');
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_the_domains_of_a_project_are_listed_and_shown(): void
    {
        $this->getJson('/api/projects/alice/domains')
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'shop.test')
            ->assertJsonPath('data.0.type', 'main');

        $this->getJson('/api/projects/alice/domains/shop.test')
            ->assertOk()
            ->assertJsonPath('data.domain', 'shop.test')
            ->assertJsonPath('data.details.document_root', '/shop.test/public_html');

        $this->getJson('/api/projects/alice/domains/other.test')->assertNotFound()->assertJsonPath('message', 'Domain not found');
        $this->getJson('/api/projects/nobody/domains')->assertNotFound();
    }

    public function test_a_domain_without_a_certificate_lists_none_and_has_none_to_show(): void
    {
        $this->getJson('/api/projects/alice/domains/installed-ssl-certs')->assertOk()->assertExactJson(['data' => []]);

        $this->getJson('/api/projects/alice/domains/shop.test/installed-ssl-cert')
            ->assertNotFound()
            ->assertExactJson(['message' => 'SSL certificate not found']);
    }

    public function test_an_ssl_check_of_a_name_that_does_not_resolve_says_so(): void
    {
        $this->getJson('/api/projects/alice/domains/shop.test/ssl-check')
            ->assertOk()
            ->assertJsonPath('data.domain', 'shop.test')
            ->assertJsonPath('data.status', 'not_resolving');
    }

    public function test_mail_dns_names_the_records_and_lowercases_the_selector(): void
    {
        $response = $this->getJson('/api/projects/alice/domains/shop.test/mail-dns?dkim_selector=Mail2026');

        $response->assertOk()
            ->assertJsonPath('data.domain', 'shop.test')
            ->assertJsonPath('data.relay', 'direct')
            ->assertJsonPath('data.checked', false);
        $this->assertSame(['spf', 'dkim', 'dmarc'], array_slice(array_column($response->json('data.records'), 'type'), 0, 3));

        $this->getJson('/api/projects/alice/domains/shop.test/mail-dns?dkim_selector=-bad-')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dkim_selector');
    }

    public function test_an_addon_over_the_limit_is_refused(): void
    {
        $this->alice->setLimit(ResourceLimit::byKey('addon_domains_limit'), 0);
        $this->alice->save();

        $this->postJson('/api/projects/alice/domains', ['domain' => 'blog.test', 'type' => 'addon'])
            ->assertUnprocessable()
            ->assertJsonPath('error_type', 'addon_domains_limit_reached');

        $this->assertSame(1, Domain::query()->count());
    }

    public function test_a_subdomain_over_the_limit_is_refused(): void
    {
        $this->alice->setLimit(ResourceLimit::byKey('subdomains_limit'), 0);
        $this->alice->save();

        $this->postJson('/api/projects/alice/domains', ['domain' => 'www2.shop.test', 'type' => 'sub', 'parent_domain' => 'shop.test'])
            ->assertUnprocessable()
            ->assertJsonPath('error_type', 'subdomains_limit_reached');
    }

    public function test_a_name_the_project_already_has_is_refused(): void
    {
        $this->postJson('/api/projects/alice/domains', ['domain' => 'shop.test', 'type' => 'addon'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Domain already exists.');

        $this->postJson('/api/projects/alice/domains', ['domain' => 'blog.test', 'type' => 'addon', 'aliases' => ['shop.test']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.aliases.0', 'Domain already exists.');

        $this->postJson('/api/projects/alice/domains', ['domain' => 'blog.test', 'type' => 'addon', 'aliases' => ['not a name']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.aliases.0', 'Invalid alias domain name.');
    }

    public function test_a_subdomain_needs_a_parent_the_project_holds_and_ends_with(): void
    {
        $this->postJson('/api/projects/alice/domains', ['domain' => 'www2.shop.test', 'type' => 'sub'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_domain.0', 'Invalid value.');

        $this->postJson('/api/projects/alice/domains', ['domain' => 'www2.other.test', 'type' => 'sub', 'parent_domain' => 'other.test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_domain.0', 'Invalid value.');

        $this->postJson('/api/projects/alice/domains', ['domain' => 'www2.blog.test', 'type' => 'sub', 'parent_domain' => 'shop.test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Invalid value.');

        $this->assertSame(['shop.test'], Domain::query()->pluck('domain')->all());
    }

    public function test_a_redirect_without_a_url_is_refused(): void
    {
        $this->putJson('/api/projects/alice/domains/shop.test', ['redirect_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.redirect_url.0', 'Enter the URL to redirect the domain to.');
    }

    public function test_an_alias_that_is_not_a_hostname_is_refused(): void
    {
        $this->putJson('/api/projects/alice/domains/shop.test', ['aliases' => ['under_score!.test']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.aliases.0', 'Invalid alias domain name.');
    }

    public function test_the_main_domain_and_a_parent_of_subdomains_are_not_deleted(): void
    {
        $this->deleteJson('/api/projects/alice/domains/shop.test')->assertUnprocessable()->assertJsonValidationErrors('domain');

        $this->domain('blog.test', 'addon');
        $this->domain('www.blog.test', 'sub', ['parent_domain' => 'blog.test']);

        $this->deleteJson('/api/projects/alice/domains/blog.test')
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Cannot delete domain with existing subdomains');
    }

    public function test_an_addon_domain_is_deleted_with_its_vhost(): void
    {
        $this->domain('blog.test', 'addon');

        $this->deleteJson('/api/projects/alice/domains/blog.test')
            ->assertOk()
            ->assertJsonPath('data.domain', 'blog.test')
            ->assertJsonStructure(['files_kept']);

        $this->assertSame(['shop.test'], Domain::query()->pluck('domain')->all());
        $this->assertStringContainsString('vhosts/blog.test.conf', implode(' ', array_merge(...$this->broker->valuesOf('files.removeIfExists'))));
    }

    public function test_a_certificate_request_plans_refuses_and_finds_no_domain(): void
    {
        $this->postJson('/api/projects/alice/domains/shop.test/request-ssl-cert', ['dry_run' => true, 'staging' => true])
            ->assertOk()
            ->assertJsonPath('data.domain', 'shop.test')
            ->assertJsonPath('data.challenge', 'http-01');

        $this->postJson('/api/projects/alice/domains/shop.test/request-ssl-cert')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Could not obtain a certificate for shop.test: shop.test is not a public hostname a certificate authority will issue for');

        $this->postJson('/api/projects/alice/domains/other.test/request-ssl-cert')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Domain not found']);
    }

    public function test_a_certificate_whose_key_is_not_its_pair_is_refused(): void
    {
        $cert = TestCertificates::selfSigned(['shop.test']);
        $other = TestCertificates::selfSigned(['shop.test']);

        $this->putJson('/api/projects/alice/domains/shop.test/install-ssl-cert', ['cert' => $cert['cert'], 'key' => $other['key']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.cert.0', 'Invalid certificate/key');

        $this->assertSame([], $this->broker->valuesOf('files.makeDirectories'));
    }

    public function test_a_matching_certificate_is_installed_and_the_webserver_reloaded(): void
    {
        $cert = TestCertificates::selfSigned(['shop.test']);

        $this->putJson('/api/projects/alice/domains/shop.test/install-ssl-cert', ['cert' => $cert['cert'], 'key' => $cert['key']])
            ->assertOk()
            ->assertJsonPath('data.domain', 'shop.test');

        $this->assertStringContainsString('/users/alice/ssl-certs', implode(' ', $this->broker->valuesOf('files.makeDirectories')[0]));
        $this->assertContains('compose.nginxReload', $this->broker->actions());
    }

    /** @param array<string, mixed> $details */
    private function domain(string $name, string $type, array $details = []): Domain
    {
        /** @var Domain $domain */
        $domain = Domain::make([
            'user_id' => $this->alice->id,
            'domain' => $name,
            'type' => $type,
            'details' => ['document_root' => "/{$name}/public_html"] + $details,
        ]);
        $domain->save();

        return $domain;
    }
}
