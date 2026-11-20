<?php

namespace Tests\Integration\System\Webserver;

use Tests\Support\TestNetwork;
use App\Models\Setting;
use App\System\Services\Webserver\Litespeed;
use App\System\Services\Webserver\Openlitespeed;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Pins what the LiteSpeed and OpenLiteSpeed drivers write and run, byte for byte,
 * against snapshots taken from the code before it was split into smaller methods.
 */
class LiteSpeedConfigCharacterisationTest extends TestCase
{
    use InMemoryDatabase;

    private const LS_CONFIG = '/opt/engine/webserver-config/litespeed/httpd_config.xml';

    private const OLS_CONFIG = '/opt/engine/webserver-config/openlitespeed/httpd_config.conf';

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([
            'default_ipv4' => TestNetwork::DOCUMENTATION_2,
            'default_ipv6' => '2001:db8::7',
        ]);
        config([
            'env.APP_LITE_PROXY_ENABLED' => false,
            'env.APP_LITE_CA_PORT' => null,
            'env.APP_LITE_AA_PORT' => null,
            'env.APP_LITE_CA_DOMAIN' => null,
            'env.APP_LITE_AA_DOMAIN' => null,
        ]);
        $this->host = new WebserverHostDouble(dirname(base_path()).'/templates');
        $this->seedAccounts();
        $this->host->files[self::LS_CONFIG] = $this->litespeedConfig();
        $this->host->files[self::OLS_CONFIG] = $this->openlitespeedConfig();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_litespeed_rebuild_config(): void
    {
        (new Litespeed($this->host))->rebuildConfig();

        $this->assertSnapshot('litespeed-rebuild', $this->host->snapshot());
    }

    public function test_litespeed_rebuild_config_with_the_app_proxy_and_cloudflare(): void
    {
        $this->enableAppProxy(8443, null);
        $this->host->files['/opt/engine/webserver-config/litespeed/cloudflare-realip.xml']
            = "<!-- panelalpha-cloudflare-realip-begin -->\n<useIpInProxyHeader>2</useIpInProxyHeader>\n<!-- panelalpha-cloudflare-realip-end -->\n";

        (new Litespeed($this->host))->rebuildConfig();

        $this->assertSnapshot('litespeed-rebuild-app-proxy', $this->host->snapshot());
    }

    public function test_litespeed_rebuild_config_with_both_app_proxy_ports_and_no_cache(): void
    {
        $this->enableAppProxy(8443, 9443);
        Setting::setRuntimeSettings([
            'default_ipv4' => TestNetwork::DOCUMENTATION_2,
            'default_ipv6' => '2001:db8::7',
            'disable-lscache' => '1',
        ]);

        (new Litespeed($this->host))->deleteDomainConfig('bob.test');

        $this->assertSnapshot('litespeed-rebuild-both-ports', $this->host->snapshot());
    }

    public function test_litespeed_toggle_modsecurity_on(): void
    {
        Setting::setRuntimeSettings(['modsec' => (string) json_encode(['mode' => 'on'])]);

        (new Litespeed($this->host))->toggleModsecurity();

        $this->assertSnapshot('litespeed-modsec-on', $this->host->snapshot());
    }

    public function test_litespeed_toggle_modsecurity_off(): void
    {
        (new Litespeed($this->host))->toggleModsecurity();

        $this->assertSnapshot('litespeed-modsec-off', $this->host->snapshot());
    }

    public function test_litespeed_toggle_lscache_adds_the_missing_cache_node(): void
    {
        (new Litespeed($this->host))->toggleLscache();

        $this->assertSnapshot('litespeed-lscache-new', $this->host->snapshot());
    }

    public function test_litespeed_toggle_lscache_switches_the_existing_engine_off(): void
    {
        $this->host->files[self::LS_CONFIG] = str_replace(
            '</httpServerConfig>',
            "  <cache>\n    <cacheEngine>7</cacheEngine>\n  </cache>\n</httpServerConfig>",
            $this->litespeedConfig(),
        );
        Setting::setRuntimeSettings([
            'default_ipv4' => TestNetwork::DOCUMENTATION_2,
            'disable-lscache' => '1',
        ]);

        (new Litespeed($this->host))->toggleLscache();

        $this->assertSnapshot('litespeed-lscache-existing-off', $this->host->snapshot());
    }

    public function test_litespeed_serial_number_without_a_previous_one(): void
    {
        (new Litespeed($this->host))->updateConfig('serial_number', 'NEW-SERIAL');

        $this->assertSnapshot('litespeed-serial-first', $this->host->snapshot());
    }

    public function test_litespeed_serial_number_replaces_and_prunes_backups(): void
    {
        $this->seedSerialBackups('litespeed');

        (new Litespeed($this->host))->updateConfig('serial_number', 'NEW-SERIAL');

        $this->assertSnapshot('litespeed-serial-replace', $this->normalizedSnapshot());
    }

    public function test_litespeed_delete_domains_configs(): void
    {
        (new Litespeed($this->host))->deleteDomainsConfigs(['alice.test', 'bob.test']);

        $this->assertSnapshot('litespeed-delete-configs', $this->host->snapshot());
    }

    public function test_openlitespeed_rebuild_config(): void
    {
        (new Openlitespeed($this->host))->rebuildConfig();

        $this->assertSnapshot('openlitespeed-rebuild', $this->host->snapshot());
    }

    public function test_openlitespeed_rebuild_config_with_the_app_proxy_and_cloudflare(): void
    {
        $this->enableAppProxy(null, 9443);
        $this->host->files['/opt/engine/webserver-config/openlitespeed/cloudflare-realip.conf']
            = "# panelalpha-cloudflare-realip-begin\nuseIpInProxyHeader 2\n# panelalpha-cloudflare-realip-end\n";

        (new Openlitespeed($this->host))->rebuildDomainConfig($this->aliceDomain());

        $this->assertSnapshot('openlitespeed-rebuild-app-proxy', $this->host->snapshot());
    }

    public function test_openlitespeed_rebuild_config_with_both_app_proxy_ports_and_no_cache(): void
    {
        $this->enableAppProxy(8443, 9443);
        Setting::setRuntimeSettings([
            'default_ipv4' => TestNetwork::DOCUMENTATION_2,
            'default_ipv6' => '2001:db8::7',
            'disable-lscache' => '1',
        ]);

        (new Openlitespeed($this->host))->deleteDomainConfig('bob.test');

        $this->assertSnapshot('openlitespeed-rebuild-both-ports', $this->host->snapshot());
    }

    public function test_openlitespeed_delete_of_an_unknown_domain_rebuilds_nothing(): void
    {
        (new Openlitespeed($this->host))->deleteDomainConfig('nobody.test');

        $this->assertSame([], $this->host->journal);
    }

    public function test_openlitespeed_toggle_modsecurity_on(): void
    {
        Setting::setRuntimeSettings(['modsec' => (string) json_encode(['mode' => 'on'])]);

        (new Openlitespeed($this->host))->toggleModsecurity();

        $this->assertSnapshot('openlitespeed-modsec-on', $this->host->snapshot());
    }

    public function test_openlitespeed_toggle_modsecurity_off_removes_the_block(): void
    {
        $this->host->files[self::OLS_CONFIG] = $this->openlitespeedConfig()
            ."\nmodule mod_security {\n  modsecurity on\n}\n";

        (new Openlitespeed($this->host))->toggleModsecurity();

        $this->assertSnapshot('openlitespeed-modsec-off', $this->host->snapshot());
    }

    public function test_openlitespeed_toggle_modsecurity_off_without_a_block_does_nothing(): void
    {
        (new Openlitespeed($this->host))->toggleModsecurity();

        $this->assertSame([], $this->host->journal);
    }

    public function test_openlitespeed_toggle_lscache_on(): void
    {
        (new Openlitespeed($this->host))->toggleLscache();

        $this->assertSnapshot('openlitespeed-lscache-on', $this->host->snapshot());
    }

    public function test_openlitespeed_toggle_lscache_off_removes_the_block(): void
    {
        $this->host->files[self::OLS_CONFIG] = $this->openlitespeedConfig()."\nmodule cache {\n  ls_enabled 1\n}\n";
        Setting::setRuntimeSettings(['disable-lscache' => '1']);

        (new Openlitespeed($this->host))->toggleLscache();

        $this->assertSnapshot('openlitespeed-lscache-off', $this->host->snapshot());
    }

    public function test_openlitespeed_toggle_lscache_off_without_a_block(): void
    {
        Setting::setRuntimeSettings(['disable-lscache' => '1']);

        (new Openlitespeed($this->host))->toggleLscache();

        $this->assertSnapshot('openlitespeed-lscache-off-absent', $this->host->snapshot());
    }

    public function test_openlitespeed_refuses_any_other_config_entry(): void
    {
        $this->expectExceptionMessage('Unsupported config entry for current webserver');

        (new Openlitespeed($this->host))->updateConfig('admin_email', 'x');
    }

    public function test_openlitespeed_reload_restart_and_delete_configs(): void
    {
        $driver = new Openlitespeed($this->host);
        $driver->reload();
        $driver->restart();
        $driver->deleteDomainsConfigs(['alice.test']);

        $this->assertSnapshot('openlitespeed-reload-restart-delete', $this->host->snapshot());
    }

    private function seedAccounts(): void
    {
        $alice = $this->makeUser('alice', ['lsphp_settings' => 'PHP_LSAPI_CHILDREN=4'], 'alice.test');
        $domain = $this->makeMainDomain($alice, 'alice.test');
        $domain->setDetails(['php_version' => '8.2', 'aliases' => ['www.alice.test']]);
        $domain->save();

        $bob = $this->makeUser('bob', [], 'bob.test');
        $bob->status = 'suspended';
        $bob->save();
        $bobDomain = $this->makeMainDomain($bob, 'bob.test');
        $bobDomain->setDetails(['php_version' => '7.4', 'document_root' => '/bob.test/web']);
        $bobDomain->save();

        $certs = $this->host->projectDirPath('bob').'/ssl-certs';
        $this->host->files["{$certs}/bob.test.pem"] = 'pem';
        $this->host->files["{$certs}/bob.test.key"] = 'key';
    }

    private function aliceDomain(): \App\Models\Domain
    {
        /** @var \App\Models\Domain */
        return \App\Models\Domain::query()->where('domain', 'alice.test')->firstOrFail();
    }

    private function enableAppProxy(?int $caPort, ?int $aaPort): void
    {
        config([
            'env.APP_LITE_PROXY_ENABLED' => true,
            'env.APP_LITE_CA_PORT' => $caPort,
            'env.APP_LITE_AA_PORT' => $aaPort,
            'env.APP_LITE_CA_DOMAIN' => 'client.example.test',
            'env.APP_LITE_AA_DOMAIN' => null,
            'env.APP_LITE_PROXY_CA_HOST' => 'app-lite-ca',
            'env.APP_LITE_PROXY_CA_PORT' => 8080,
            'env.APP_LITE_PROXY_AA_HOST' => 'app-lite-aa',
            'env.APP_LITE_PROXY_AA_PORT' => 8081,
            'env.APP_LITE_PROXY_WS_HOST' => 'app-lite-ws',
            'env.APP_LITE_PROXY_WS_PORT' => 6001,
            'env.APP_LITE_PROXY_WS_REWRITE_HOST' => 'app-lite-ws-rewrite',
            'env.APP_LITE_PROXY_WS_REWRITE_PORT' => 6002,
        ]);
    }

    private function seedSerialBackups(string $slug): void
    {
        $dir = "/opt/engine/webserver-config/{$slug}";
        $this->host->files["{$dir}/serial.no"] = 'OLD';
        $this->host->files["{$dir}/license.key"] = 'LICENSE';
        $this->host->files["{$dir}/other.conf"] = 'x';
        for ($i = 100; $i < 112; $i++) {
            $this->host->files["{$dir}/serial.no.bak{$i}"] = 'old';
        }
        $this->host->files["{$dir}/license.key.bak100"] = 'old';
        $this->host->files["{$dir}/trial.key.bak100"] = 'old';
    }

    private function normalizedSnapshot(): string
    {
        return (string) preg_replace('/\.bak1[0-9]{9}\b/', '.bak{time}', $this->host->snapshot());
    }

    private function litespeedConfig(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<httpServerConfig>
  <serverName>engine</serverName>
  <security>
    <censorshipControl>
      <enableCensorship>0</enableCensorship>
    </censorshipControl>
    <accessDenyDir>/</accessDenyDir>
  </security>
  <listenerList>
    <listener><name>old</name></listener>
  </listenerList>
  <virtualHostList>
    <virtualHost><name>old</name></virtualHost>
  </virtualHostList>
</httpServerConfig>

XML;
    }

    private function openlitespeedConfig(): string
    {
        return <<<'CONF'
serverName engine
user nobody

listener Default {
  address *:80
  secure 0
  map old old.test
}

virtualhost old {
  vhRoot /old
}

module cgi {
}
CONF;
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
