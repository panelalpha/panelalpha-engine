<?php

namespace Tests\Unit\System\Webserver;

use Tests\Support\TestNetwork;
use App\Models\Domain;
use App\Models\Setting;
use App\System\Services\Webserver\Apache;
use App\System\Services\Webserver\Nginx;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Pins the vhost the Apache and Nginx drivers write for a domain, and the commands around it.
 */
class ApacheNginxDomainConfigCharacterisationTest extends TestCase
{
    use InMemoryDatabase;

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2, 'default_ipv6' => '2001:db8::7']);
        $this->host = new WebserverHostDouble(dirname(base_path()).'/templates');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_apache_vhost_without_a_certificate(): void
    {
        (new Apache($this->host))->addDomain($this->domain('alice', 'alice.test', ['php_version' => '8.2', 'aliases' => ['www.alice.test']]));

        $this->assertSnapshot('apache-vhost-plain', $this->host->snapshot());
    }

    public function test_apache_vhost_with_a_certificate_for_a_suspended_account(): void
    {
        $domain = $this->domain('bob', 'bob.test', ['php_version' => '7.4', 'document_root' => '/bob.test/web'], 'suspended');
        $this->host->files[$this->host->projectDirPath('bob').'/ssl-certs/bob.test.crt'] = 'crt';

        (new Apache($this->host))->rebuildDomainConfig($domain);

        $this->assertSnapshot('apache-vhost-ssl', $this->host->snapshot());
    }

    public function test_apache_delete_domains_configs(): void
    {
        (new Apache($this->host))->deleteDomainsConfigs(['alice.test', 'bob.test']);

        $this->assertSnapshot('apache-delete-configs', $this->host->snapshot());
    }

    public function test_nginx_vhost_without_a_certificate(): void
    {
        (new Nginx($this->host))->addDomain($this->domain('alice', 'alice.test', ['php_version' => '8.2', 'aliases' => ['www.alice.test']]));

        $this->assertSnapshot('nginx-vhost-plain', $this->host->snapshot());
    }

    public function test_nginx_vhost_with_a_servable_certificate(): void
    {
        $domain = $this->domain('bob', 'bob.test', ['php_version' => '7.4', 'document_root' => '/bob.test/web'], 'suspended');
        $certs = $this->host->projectDirPath('bob').'/ssl-certs';
        $this->host->files["{$certs}/bob.test.pem"] = 'pem';
        $this->host->files["{$certs}/bob.test.key"] = 'key';

        (new Nginx($this->host))->rebuildDomainConfig($domain);

        $this->assertSnapshot('nginx-vhost-ssl', $this->host->snapshot());
    }

    public function test_nginx_reload_without_a_rebind_reloads_in_the_container(): void
    {
        (new Nginx($this->host))->reload();

        $this->assertSnapshot('nginx-reload', $this->host->snapshot());
    }

    /** @param array<string, mixed> $details */
    private function domain(string $username, string $name, array $details, ?string $status = null): Domain
    {
        $user = $this->makeUser($username, [], $name);
        if ($status !== null) {
            $user->status = $status;
            $user->save();
        }
        $domain = $this->makeMainDomain($user, $name);
        $domain->setDetails($details);
        $domain->save();

        return $domain;
    }

    private function assertSnapshot(string $name, string $actual): void
    {
        $file = dirname(__DIR__, 3).'/fixtures/webserver-snapshots/'.$name.'.txt';
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $actual);
        }
        $this->assertFileExists($file);
        $this->assertSame(file_get_contents($file), $actual);
    }
}
