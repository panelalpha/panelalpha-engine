<?php

namespace Tests\Integration\Console\Ssl;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Domain;
use App\Models\Setting;
use App\System;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** The certificate commands up to where an authority or the host is asked: refusals, dry-run plans, and the engine request's argv. */
class SslCertificateCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::flushCache();
        $shop = $this->makeUser('shop');
        $this->makeMainDomain($shop, 'shop.example.com');
        Domain::query()->create(['user_id' => $shop->id, 'domain' => 'shop.local', 'type' => 'addon', 'details' => ['document_root' => '/shop.local/public_html']]);
        Domain::query()->create(['user_id' => $shop->id, 'domain' => 'plain.example.com', 'type' => 'addon', 'details' => ['document_root' => '/plain.example.com/public_html', 'ssl_disabled' => true]]);
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        parent::tearDown();
    }

    public function test_request_refuses_a_name_that_is_not_a_project_domain(): void
    {
        $this->artisan('ssl:project-cert:request Nowhere.example.com')
            ->expectsOutput('No project domain called nowhere.example.com on this engine.')
            ->assertExitCode(1);
    }

    public function test_a_dry_run_names_the_authority_account_and_challenge(): void
    {
        Setting::set('cert_email', 'ops@example.com');

        $this->artisan('ssl:project-cert:request shop.example.com --dry-run --staging')
            ->expectsOutput('Would request a certificate for shop.example.com')
            ->expectsOutputToContain('  authority: https://acme-staging-v02.api.letsencrypt.org/directory')
            ->expectsOutputToContain('  account:   ')
            ->expectsOutput('  challenge: http-01, served by this host at http://shop.example.com/.well-known/acme-challenge/')
            ->assertExitCode(0);
    }

    public function test_a_dry_run_reports_why_a_name_cannot_be_certified(): void
    {
        $this->artisan('ssl:project-cert:request shop.local --dry-run')
            ->expectsOutputToContain('Cannot request a certificate: ')
            ->assertExitCode(1);
    }

    public function test_a_request_for_an_ineligible_name_keeps_the_current_certificate(): void
    {
        $this->artisan('ssl:project-cert:request shop.local')
            ->expectsOutputToContain('Could not obtain a certificate for shop.local: ')
            ->expectsOutput('The site keeps the certificate it already had.')
            ->assertExitCode(1);
    }

    public function test_renew_with_nothing_matching_renews_nothing(): void
    {
        $this->artisan('ssl:project-cert:renew --domain=Nowhere.example.com --days=10')
            ->expectsOutput('0 renewed, 0 failed')
            ->assertExitCode(0);
    }

    public function test_remove_refuses_an_unknown_domain_or_one_with_ssl_off(): void
    {
        $this->artisan('ssl:project-cert:remove nowhere.example.com')
            ->expectsOutput('No project domain called nowhere.example.com on this engine.')
            ->assertExitCode(1);
        $this->artisan('ssl:project-cert:remove plain.example.com')
            ->expectsOutput('plain.example.com has SSL turned off, so it has no certificate to put back.')
            ->assertExitCode(1);
    }

    public function test_remove_reports_a_host_that_cannot_put_the_certificate_back(): void
    {
        $system = new class extends RecordingSystem {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \RuntimeException('host unreachable');
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                throw new \RuntimeException('host unreachable');
            }
        };
        $this->app->instance(System::class, $system);

        $this->artisan('ssl:project-cert:remove shop.example.com')
            ->expectsOutput('Could not put another certificate on shop.example.com: host unreachable')
            ->assertExitCode(1);
    }

    /** A host whose request script prints the given output and succeeds or fails. */
    private function engineHost(bool $succeeds, string $stdout = '', string $stderr = ''): RecordingSystem
    {
        $system = new class ($succeeds, $stdout, $stderr) extends RecordingSystem {
            public function __construct(private bool $succeeds, private string $stdout, private string $stderr)
            {
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                $this->commands[] = $cmd;
                $onOutput !== null && $this->stdout !== '' && $onOutput(Process::OUT, $this->stdout);
                $onOutput !== null && $this->stderr !== '' && $onOutput(Process::ERR, $this->stderr);
                if ($this->succeeds) {
                    Setting::set('cert_domain', 'engine.example.com');
                }

                return $this->succeeds ? FakeProcess::ok($this->stdout) : FakeProcess::failed($this->stderr);
            }
        };
        $this->app->instance(System::class, $system);

        return $system;
    }

    public function test_engine_cert_runs_the_request_script_and_reports_the_served_name(): void
    {
        $system = $this->engineHost(true, "Requesting a certificate\n", "certbot warning\n");

        $this->artisan('ssl:engine-cert:request --domain=engine.example.com --email=ops@example.com --staging --skip-dns-check')
            ->expectsOutput('Requesting a certificate for engine.example.com')
            ->expectsOutput('Serving https://engine.example.com:2011')
            ->assertExitCode(0);

        $argv = implode(' ', (array) $system->commands[0]);
        $this->assertStringContainsString('engine.example.com', $argv);
        $this->assertStringContainsString('ops@example.com', $argv);
        $this->assertStringContainsString('staging', $argv);
        $this->assertStringContainsString('skip-dns-check', $argv);
        $this->assertStringNotContainsString('force-renewal', $argv);
    }

    public function test_engine_cert_dry_run_falls_back_to_the_default_name_and_serves_nothing_new(): void
    {
        $this->engineHost(true);

        $this->artisan('ssl:engine-cert:request --dry-run')
            ->expectsOutput('Requesting a certificate for the default name derived from the public address')
            ->doesntExpectOutputToContain('Serving https://')
            ->assertExitCode(0);
    }

    public function test_engine_cert_failure_keeps_the_served_certificate(): void
    {
        Setting::set('cert_domain', 'old.example.com');
        $this->engineHost(false, '', "challenge failed\n");

        $this->artisan('ssl:engine-cert:request --force-renewal')
            ->expectsOutput('Requesting a certificate for old.example.com')
            ->expectsOutput('The certificate was not issued; the served certificate is unchanged')
            ->assertExitCode(1);
    }

    public function test_engine_cert_refuses_a_malformed_value_before_running_anything(): void
    {
        $system = $this->engineHost(true);

        $this->artisan('ssl:engine-cert:request --email=not-an-email')
            ->expectsOutputToContain('not-an-email')
            ->assertExitCode(1);

        $this->assertSame([], $system->commands);
    }
}
