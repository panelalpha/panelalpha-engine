<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\EngineAddressSection;
use App\Jobs\RebuildDomainConfigs;
use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Prompts\Key;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\Support\TlsServer;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure address`: the address in `.env`, the certificate name and tunnel in settings, and the request. */
class EngineAddressSectionFlowTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const URL = 0;

    private const NAME = 1;

    private const REQUEST = 2;

    private const TUNNEL = 3;

    private const CHECK = 4;

    private const BACK = 5;

    private ?TlsServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startWizardScreen();
        Queue::fake();
        Setting::setRuntimeSettings(['cert_domain' => '', 'cert_email' => '', 'default_tunnel' => null]);
        config(['app.url' => '']);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        Setting::clearRuntimeSettings();
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_an_engine_without_an_address_is_told_so_and_can_be_given_one(): void
    {
        $url = 'https://'.$this->silentAddress();
        $this->keys([Key::ENTER, self::typed($url), Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('Address: (not set)');
        $this->assertScreenShows('No client can reach this engine at that address.');
        $this->assertStringContainsString("APP_URL={$url}", $this->envContents());
        $this->assertSame($url, config('app.url'));
        $this->assertSame(["Address set to {$url}."], $section->receipt());
        $this->assertScreenShows('Nothing answered on that address from here.');
    }

    public function test_an_address_the_env_file_refuses_is_reported_and_not_used(): void
    {
        unlink($this->envPath());
        $url = 'https://'.$this->silentAddress();
        $this->keys([Key::ENTER, self::typed($url), Key::ENTER, ...self::row(self::CHECK), ...self::row(self::BACK)]);

        $section = new EngineAddressSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('No .env file at '.$this->envPath().'.');
        $this->assertScreenDoesNotShow('The engine uses this on its next request');
        $this->assertSame('', config('app.url'));
        $this->assertSame([], $section->receipt());
    }

    public function test_an_address_without_a_scheme_is_refused_until_it_has_one(): void
    {
        $url = 'https://'.$this->silentAddress();
        $this->keys([Key::ENTER, 'panel.example.test'.Key::ENTER, self::typed($url, 'panel.example.test'), Key::ENTER, ...self::row(self::BACK)]);

        (new EngineAddressSection())->run(false);

        $this->assertScreenShows('Give the whole address, including https:// and the port.');
        $this->assertStringContainsString("APP_URL={$url}", $this->envContents());
    }

    public function test_a_dry_run_says_what_the_address_would_become_and_writes_nothing(): void
    {
        $url = 'http://'.$this->silentAddress();
        $this->keys([Key::ENTER, self::typed($url), Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(true);

        $this->assertScreenShows("Dry run: APP_URL would become {$url}.");
        $this->assertStringNotContainsString('APP_URL', $this->envContents());
        $this->assertSame([], $section->receipt());
    }

    public function test_keeping_the_address_it_has_changes_nothing(): void
    {
        $url = 'http://'.$this->silentAddress();
        config(['app.url' => $url]);
        $this->keys([...self::row(self::URL), Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows('The address is plain http.');
        $this->assertStringNotContainsString('APP_URL', $this->envContents());
        $this->assertSame([], $section->receipt());
    }

    public function test_an_env_file_without_an_app_key_is_named_as_probably_not_the_one_in_use(): void
    {
        file_put_contents($this->envPath(), "APP_URL=\n");
        $this->keys([...self::row(self::BACK)]);

        (new EngineAddressSection())->run(false);

        $this->assertScreenShows($this->envPath().' has no APP_KEY, so it is almost certainly not the file this engine boots from.');
    }

    public function test_the_certificate_name_and_email_are_checked_and_stored(): void
    {
        $this->keys([
            ...self::row(self::NAME),
            'q'.Key::ENTER, self::typed('Panel.Example.test', 'q'),
            'nobody'.Key::ENTER, self::typed('ops@example.test', 'nobody'),
            Key::ENTER,
            ...self::row(self::BACK),
        ]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows('A certificate name is a full hostname, like panel.example.com.');
        $this->assertScreenShows('That is not an email address.');
        $this->assertSame('panel.example.test', Setting::get('cert_domain'));
        $this->assertSame('ops@example.test', Setting::get('cert_email'));
        $this->assertSame(['Certificate name set to panel.example.test.'], $section->receipt());
        $this->assertScreenShows('Certificate name: panel.example.test');
        Queue::assertPushed(RebuildDomainConfigs::class);
    }

    public function test_clearing_the_certificate_name_goes_back_to_the_default(): void
    {
        Setting::set('cert_domain', 'old.example.test');
        $this->keys([...self::row(self::NAME), self::typed('', 'old.example.test'), Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertNull(Setting::get('cert_domain'));
        $this->assertSame(['Certificate name cleared — the default for this address will be used.'], $section->receipt());
        $this->assertScreenShows('Certificate name: (default — the .panelalpha.direct name for this address)');
    }

    public function test_a_dry_run_of_the_name_stores_nothing(): void
    {
        $this->keys([...self::row(self::NAME), 'panel.example.test'.Key::ENTER, Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(true);

        $this->assertScreenShows('Dry run: nothing was written.');
        $this->assertSame('', Setting::get('cert_domain'));
        Queue::assertNothingPushed();
    }

    public function test_a_name_the_database_refuses_is_reported(): void
    {
        $this->settingsRefuseWrites();
        $this->keys([...self::row(self::NAME), 'panel.example.test'.Key::ENTER, Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows('settings are read-only');
        $this->assertSame([], $section->receipt());
        $this->assertNull(Setting::get('cert_domain'));
    }

    public function test_without_a_database_only_the_address_can_be_changed(): void
    {
        Setting::clearRuntimeSettings();
        config(['database.default' => 'no-such-connection']);
        $this->keys([...self::row(self::NAME), ...self::row(self::REQUEST), ...self::row(self::TUNNEL), ...self::row(self::CHECK), ...self::row(self::BACK)]);

        $section = new EngineAddressSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('Certificate name: (unknown — the database could not be read)');
        $this->assertScreenShows('New project tunnel: (unknown — the database could not be read)');
        $this->assertScreenShows('The database could not be read, so the certificate name cannot be shown or changed.');
        $this->assertScreenDoesNotShow('About to ask Let\'s Encrypt');
        $this->assertScreenDoesNotShow('What a project created without naming a tunnel is published under.');
        $this->assertSame([], $section->receipt());
    }

    public function test_the_tunnel_for_new_projects_is_stored(): void
    {
        $this->keys([...self::row(self::TUNNEL), Key::DOWN, Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertSame(DomainPlan::TUNNEL_NONE, Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL));
        $this->assertSame(['New projects without a tunnel will use: none.'], $section->receipt());
        $this->assertScreenShows('New project tunnel: none');
    }

    public function test_a_dry_run_of_the_tunnel_stores_nothing(): void
    {
        Setting::set('default_tunnel', DomainPlan::TUNNEL_NONE);
        $this->keys([...self::row(self::TUNNEL), Key::UP, Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        (new EngineAddressSection())->run(true);

        $this->assertScreenShows('Dry run: the new project tunnel would become panelalpha.');
        $this->assertSame(DomainPlan::TUNNEL_NONE, Setting::get('default_tunnel'));
    }

    public function test_a_tunnel_the_database_refuses_is_reported(): void
    {
        $this->settingsRefuseWrites();
        $this->keys([...self::row(self::TUNNEL), Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows('settings are read-only');
        $this->assertSame([], $section->receipt());
    }

    public function test_a_rehearsed_request_runs_the_dry_run_and_installs_nothing(): void
    {
        Setting::set('cert_domain', 'panel.example.test');
        $this->brokerAnswers("\e[32mSimulating a certificate request\e[0m");
        $this->keys([...self::row(self::REQUEST), Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows("About to ask Let's Encrypt for a certificate for panel.example.test.");
        $this->assertScreenShows('Simulating a certificate request');
        $this->assertScreenShows('That was a rehearsal. Nothing was installed.');
        $this->assertSame([], $section->receipt());
        $this->assertCount(1, $this->brokerRequests());
        $this->assertStringContainsString('host.requestEngineCertificate', $this->brokerRequests()[0]);
        $this->assertStringEndsWith('dry-run', $this->brokerRequests()[0]);
    }

    public function test_a_real_request_that_succeeds_goes_on_the_receipt(): void
    {
        Setting::set('cert_domain', 'panel.example.test');
        $this->brokerAnswers('');
        $this->keys([...self::row(self::REQUEST), ...self::row(0), Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows('(no output)');
        $this->assertSame(['Issued a certificate for panel.example.test.'], $section->receipt());
        $this->assertStringNotContainsString('dry-run', $this->brokerRequests()[0]);
        $this->assertStringNotContainsString('staging', $this->brokerRequests()[0]);
    }

    public function test_a_staging_request_that_fails_says_so(): void
    {
        $this->brokerAnswers('DNS problem: NXDOMAIN', 1);
        $this->keys([...self::row(self::REQUEST), ...self::row(2), Key::ENTER, ...self::row(self::BACK)]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertScreenShows('for the default name for this address');
        $this->assertScreenShows('DNS problem: NXDOMAIN');
        $this->assertScreenShows('The request did not succeed.');
        $this->assertSame([], $section->receipt());
        $this->assertStringEndsWith('staging', $this->brokerRequests()[0]);
    }

    public function test_declining_a_real_request_or_going_back_requests_nothing(): void
    {
        $this->brokerAnswers('');
        $this->keys([
            ...self::row(self::REQUEST), ...self::row(0), 'n', Key::ENTER,
            ...self::row(self::REQUEST), ...self::row(3),
            ...self::row(self::BACK),
        ]);

        $section = new EngineAddressSection();
        $section->run(false);

        $this->assertSame([], $this->brokerRequests());
        $this->assertSame([], $section->receipt());
    }

    public function test_a_dry_run_of_the_request_makes_none(): void
    {
        $this->brokerAnswers('');
        $this->keys([...self::row(self::REQUEST), Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        (new EngineAddressSection())->run(true);

        $this->assertScreenShows('Dry run: no request was made.');
        $this->assertSame([], $this->brokerRequests());
    }

    public function test_a_self_signed_certificate_about_to_expire_is_warned_about(): void
    {
        $this->server = TlsServer::start($this->wizardDir, TestNetwork::HOSTNAME_LOOPBACK, 'other.example.test', 10);
        config(['app.url' => 'https://'.$this->server->address]);
        $this->keys([...self::row(self::BACK)]);

        (new EngineAddressSection())->run(false);

        $this->assertScreenShows('Serving: other.example.test, self-signed, expires');
        $this->assertScreenShows('The certificate is self-signed, so clients will refuse it');
        $this->assertScreenShows('The certificate does not cover "'.TestNetwork::HOSTNAME_LOOPBACK.'". It is for other.example.test.');
        $this->assertMatchesRegularExpression('/The certificate expires in (9|10) days\./', $this->screen());
    }

    public function test_an_issued_certificate_for_the_address_raises_no_warning(): void
    {
        $this->server = TlsServer::start($this->wizardDir, TestNetwork::HOSTNAME_LOOPBACK, TestNetwork::HOSTNAME_LOOPBACK, 90, false);
        config(['app.url' => 'https://'.$this->server->address]);
        $this->keys([Key::ENTER, Key::DOWN, Key::ENTER]);

        $this->assertSame(0, (new EngineAddressSection())->run(false));

        $this->assertScreenShows('Serving: '.TestNetwork::HOSTNAME_LOOPBACK.', issued by Wizard Test CA, expires');
        $this->assertScreenDoesNotShow('self-signed');
        $this->assertScreenDoesNotShow('does not cover');
        $this->assertScreenDoesNotShow('The certificate expires');
    }

    /** A real settings table that can be read and refuses every write. */
    private function settingsRefuseWrites(): void
    {
        Setting::clearRuntimeSettings();
        $this->bootInMemoryDatabase();
        $this->promptsAnswerKeys();
        DB::unprepared("CREATE TRIGGER refuse_insert BEFORE INSERT ON settings BEGIN SELECT RAISE(ABORT, 'settings are read-only'); END");
        DB::unprepared("CREATE TRIGGER refuse_update BEFORE UPDATE ON settings BEGIN SELECT RAISE(ABORT, 'settings are read-only'); END");
    }

    /** host:port on a loopback address that is not the engine's own, where nothing listens. */
    private function silentAddress(): string
    {
        $socket = stream_socket_server('tcp://'.TestNetwork::HOSTNAME_LOOPBACK.':0');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return $address;
    }
}
