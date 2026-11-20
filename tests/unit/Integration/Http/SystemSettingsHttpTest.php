<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\BackupContainer;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\System;
use App\System\Services\Webserver;
use Tests\Integration\Ssl\TestCertificates;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The /system endpoints that build their own System, answered by a scripted broker instead of the host. */
class SystemSettingsHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const CERTIFICATE = '/opt/panelalpha/shared-hosting/crt/server.cert';

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        $this->runningWebserver('nginx-proxy');
    }

    protected function tearDown(): void
    {
        $this->broker->uninstall();
        $this->forgetWebserver();
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_info_reports_the_settings_the_webserver_and_the_default_store(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(7));
        Setting::set('default_tunnel', 'none');
        $store = BackupContainer::create(['name' => 'offsite', 'driver' => 'local', 'location' => '/backups', 'credentials' => []]);
        $store->makeDefault();

        $response = $this->getJson('/api/system/info')->assertOk();

        $response->assertJsonPath('data.version', config('system.version'));
        $response->assertJsonPath('data.api_url', 'http://localhost/api');
        $response->assertJsonPath('data.mcp_url', 'http://localhost/mcp');
        $response->assertJsonPath('data.default_ipv4', TestNetwork::public(7));
        $response->assertJsonPath('data.default_tunnel', 'none');
        $response->assertJsonPath('data.default_backup_container', [
            'id' => $store->id,
            'name' => 'offsite',
            'driver' => 'local',
            'location' => '/backups',
        ]);
        $response->assertJsonPath('data.webserver.slug', 'nginx-proxy');
        $response->assertJsonPath('data.served_certificate', null);
        $response->assertJsonPath('data.unreadable_secrets.count', 0);
        $response->assertJsonPath('data.orphan_account_containers.count', 0);
        $this->assertContains('compose.serviceContainers', $this->broker->actions());
    }

    public function test_info_reports_the_names_of_the_served_certificate(): void
    {
        config(['app.url' => 'https://engine.example.test']);
        $this->broker->hostFile(self::CERTIFICATE, TestCertificates::selfSigned(['engine.example.test'])['cert']);

        $response = $this->getJson('/api/system/info')->assertOk();

        $response->assertJsonPath('data.url', 'https://engine.example.test');
        $response->assertJsonPath('data.served_certificate.names', ['engine.example.test']);
    }

    public function test_info_reports_no_certificate_for_a_file_that_is_not_one(): void
    {
        $this->broker->hostFile(self::CERTIFICATE, "not a certificate\n");

        $this->getJson('/api/system/info')->assertOk()->assertJsonPath('data.served_certificate', null);
    }

    public function test_info_reports_no_certificate_when_none_is_on_disk(): void
    {
        $this->broker->answer('files.isFile', exit: 1, match: self::CERTIFICATE);

        $this->getJson('/api/system/info')->assertOk()->assertJsonPath('data.served_certificate', null);
        $this->assertNotContains('files.copy', $this->broker->actions());
    }

    public function test_ssl_config_reads_back_what_was_saved(): void
    {
        $this->getJson('/api/system/ssl-config')
            ->assertOk()
            ->assertJsonPath('data.sites_base_domain', null)
            ->assertJsonPath('data.shared_zone', true)
            ->assertJsonPath('data.shared_zone_issuance', false);

        $this->putJson('/api/system/ssl-config', ['shared_zone_issuance' => true, 'acme_email' => 'ops@example.com'])
            ->assertOk()
            ->assertJsonPath('data.shared_zone_issuance', true)
            ->assertJsonPath('data.acme_email', 'ops@example.com');
        $this->assertSame('1', Setting::get('ssl_shared_zone_issuance'));

        $this->putJson('/api/system/ssl-config', ['shared_zone_issuance' => false, 'acme_email' => ''])
            ->assertOk()
            ->assertJsonPath('data.shared_zone_issuance', false);
        $this->assertSame('0', Setting::get('ssl_shared_zone_issuance'));
        $this->assertNull(Setting::get('acme_email'));
    }

    public function test_ssl_config_refuses_a_base_domain_no_certificate_can_be_issued_for(): void
    {
        $this->putJson('/api/system/ssl-config', ['sites_base_domain' => 'localhost'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sites_base_domain.0', 'localhost is not a hostname sites can be named under.');

        $this->assertNull(Setting::get('default_wildcard_domain'));
    }

    public function test_an_engine_certificate_request_passes_its_flags_and_reports_the_served_certificate(): void
    {
        config(['app.url' => 'https://engine.example.test']);
        $this->broker->answer('host.requestEngineCertificate', "\e[32missued\e[0m\n");
        $this->broker->hostFile(self::CERTIFICATE, TestCertificates::selfSigned(['engine.example.test'])['cert']);

        $response = $this->putJson('/api/system/engine-certificate', [
            'domain' => 'engine.example.test',
            'email' => 'ops@example.com',
            'staging' => true,
            'dry_run' => true,
            'force_renewal' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.output', 'issued')
            ->assertJsonPath('data.url', 'https://engine.example.test')
            ->assertJsonPath('data.served_certificate.names', ['engine.example.test']);
        $values = implode(' ', $this->broker->valuesOf('host.requestEngineCertificate')[0]);
        $this->assertStringContainsString('engine.example.test', $values);
        $this->assertStringContainsString('staging', $values);
        $this->assertStringContainsString('dry-run', $values);
        $this->assertStringNotContainsString('force-renewal', $values);
    }

    public function test_a_failed_engine_certificate_request_is_refused_with_the_script_output(): void
    {
        $this->broker->answer('host.requestEngineCertificate', '', 1, 'DNS does not point here');

        $this->putJson('/api/system/engine-certificate', ['domain' => 'engine.example.test'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.domain.0',
                'The certificate was not issued; the served certificate is unchanged. DNS does not point here',
            );
    }

    public function test_an_update_starts_the_script_with_the_license_key(): void
    {
        $system = new class extends System {
            public ?string $licenseKey = 'unset';

            public function isUpdateScriptRunning(): bool
            {
                return false;
            }

            public function runUpdateScript(?string $licenseKey = null): void
            {
                $this->licenseKey = $licenseKey;
            }

            public function getLatestUpdateInfo(): ?array
            {
                return ['result' => null, 'pid' => 42];
            }
        };
        $this->app->instance(System::class, $system);

        $this->putJson('/api/system/update', ['license_key' => 'LIC-123'])
            ->assertOk()
            ->assertJsonPath('data.pid', 42);
        $this->assertSame('LIC-123', $system->licenseKey);

        $this->putJson('/api/system/update', ['license_key' => ''])->assertOk();
        $this->assertNull($system->licenseKey);
    }

    public function test_a_webserver_change_away_from_nginx_proxy_is_refused(): void
    {
        $this->putJson('/api/system/change-webserver', ['new_webserver' => 'caddy'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_webserver.0', 'Invalid value');

        $this->putJson('/api/system/change-webserver', ['new_webserver' => 'apache'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_webserver.0', 'Switching the webserver away from nginx-proxy is temporarily disabled. Only nginx-proxy is supported right now (required for DinD projects).');

        $this->putJson('/api/system/change-webserver', ['new_webserver' => 'nginx-proxy', 'serial_number' => 'ABC'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.serial_number.0', 'Serial number is only supported when switching to LiteSpeed');
    }

    public function test_the_panel_password_cannot_be_reset_on_nginx_proxy(): void
    {
        $this->putJson('/api/system/reset-webserver-panel-password')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Operation not supported on current webserver');
    }

    public function test_the_litespeed_panel_password_is_reset(): void
    {
        $this->runningWebserver('litespeed');

        $password = $this->putJson('/api/system/reset-webserver-panel-password')->assertOk()->json('data.new_password');

        $this->assertSame(16, strlen((string) $password));
        $this->assertContains('compose.litespeedResetAdminPassword', $this->broker->actions());
    }

    public function test_a_docker_error_while_resetting_the_panel_password_is_a_502(): void
    {
        $this->forgetWebserver();
        $this->broker->answer('compose.serviceContainers', '', 1, 'Error response from daemon: dead');

        $this->putJson('/api/system/reset-webserver-panel-password')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Error response from daemon: dead');
    }

    public function test_webserver_config_is_refused_on_nginx_proxy(): void
    {
        $this->putJson('/api/system/webserver-config', ['serial_number' => 'ABC'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unsupported config entry for current webserver');
    }

    public function test_a_docker_error_while_updating_webserver_config_is_a_502(): void
    {
        $this->forgetWebserver();
        $this->broker->answer('compose.serviceContainers', '', 1, 'Error response from daemon: dead');

        $this->putJson('/api/system/webserver-config', ['serial_number' => 'ABC'])->assertStatus(502);
    }

    public function test_a_litespeed_serial_the_check_rejects_is_refused(): void
    {
        $this->runningWebserver('litespeed');
        $this->broker->answer('docker.checkLiteSpeedSerial', '', 1, 'invalid serial');

        $this->putJson('/api/system/webserver-config', ['serial_number' => 'BAD'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.serial_number.0', 'The LiteSpeed serial number is invalid.');
    }

    public function test_a_litespeed_serial_the_check_accepts_is_saved(): void
    {
        $this->runningWebserver('litespeed');

        $this->putJson('/api/system/webserver-config', ['serial_number' => 'GOOD'])
            ->assertOk()
            ->assertJsonPath('data.serial_number', 'GOOD');
        $this->assertContains('docker.checkLiteSpeedSerial', $this->broker->actions());
    }

    public function test_a_test_email_applies_the_config_it_carries_first(): void
    {
        $this->broker->answer('compose.eximSendTestEmail', "accepted\n");
        $this->broker->answer('files.isDirectory', exit: 1, match: '/config/exim/');

        $response = $this->postJson('/api/system/exim-send-test-email', [
            'email' => 'ops@example.com',
            'config' => ['smarthost_provider' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => '587'],
        ]);

        $response->assertOk();
        $this->assertSame('smtp.example.com', Setting::getEximConfig()['smtp_host']);
        $this->assertContains('ops@example.com', $this->broker->valuesOf('compose.eximSendTestEmail')[0]);
        $this->assertContains('compose.eximUpdateConfig', $this->broker->actions());
    }

    public function test_a_test_email_without_config_leaves_the_stored_one(): void
    {
        $this->postJson('/api/system/exim-send-test-email', ['email' => 'ops@example.com'])->assertOk();

        $this->assertNull(Setting::get('exim'));
        $this->assertCount(1, $this->broker->valuesOf('compose.eximSendTestEmail'));
    }

    public function test_network_config_saves_the_addresses_and_answers_with_the_info(): void
    {
        $response = $this->putJson('/api/system/network-config', [
            'default_ipv4' => TestNetwork::public(9),
            'default_ipv6' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.default_ipv4', TestNetwork::public(9))
            ->assertJsonPath('reload_pending', false);
        $this->assertSame(TestNetwork::public(9), Setting::get('default_ipv4'));
        $this->assertSame('', Setting::get('default_ipv6'));
    }

    public function test_a_nat_map_is_stored_listed_and_deleted(): void
    {
        $local = TestNetwork::private(5);
        $public = TestNetwork::public(5);

        $this->putJson('/api/system/ipv4-nat-maps', ['local_ip' => $local, 'public_ip' => $local])
            ->assertUnprocessable()
            ->assertJsonPath('errors.local_ip.0', 'Local IP and public IP must be different.');

        $id = $this->putJson('/api/system/ipv4-nat-maps', ['local_ip' => $local, 'public_ip' => $public])
            ->assertOk()
            ->assertJsonPath('data.local_ip', $local)
            ->assertJsonPath('reload_pending', false)
            ->json('data.id');

        $this->getJson('/api/system/ipv4-nat-maps')->assertOk()->assertJsonPath('data.0.public_ip', $public);

        $this->deleteJson("/api/system/ipv4-nat-maps/{$id}")->assertOk()->assertJsonPath('data.local_ip', $local);
        $this->assertSame(0, Ipv4NatMap::query()->count());
    }

    public function test_a_nat_rebuild_reports_the_default_address_it_kept(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(3));

        $this->postJson('/api/system/ipv4-nat-maps/rebuild', ['replace_default_ipv4' => false])
            ->assertOk()
            ->assertJsonPath('data.maps', [])
            ->assertJsonPath('data.default_ipv4_replaced', false)
            ->assertJsonPath('data.default_ipv4_current', TestNetwork::public(3))
            ->assertJsonPath('reload_pending', false);
    }

    private function runningWebserver(string $slug): void
    {
        $this->forgetWebserver();
        $this->broker->answer('compose.serviceContainers', json_encode(['Labels' => "com.panelalpha.webserver={$slug}"]));
    }

    private function forgetWebserver(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
    }
}
