<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use App\System\Project\Dind\AppHealth;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/**
 * What captureHealth(), signal() and the bug-report path queue, and what they refuse to.
 */
class CaptureHealthAndSignalTest extends TestCase
{
    use InMemoryDatabase;

    private const INSTALL_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private string $root;

    private string $username;

    private ?DeployLogger $logger = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir().'/pa-health-cap-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/outbox', 0777, true);
        file_put_contents($this->root.'/install-id', self::INSTALL_ID);
        $this->username = 'hc'.bin2hex(random_bytes(4));

        config([
            'telemetry.enabled' => true,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->root.'/outbox',
            'telemetry.pin_file' => $this->root.'/install-id',
            'telemetry.failure_timeout' => 5,
            'telemetry.source_bundle.mode' => 'off',
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
        ]);
        Telemetry::resetCache();
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($this->root.'/outbox', new RecordingRunner));
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
    }

    protected function tearDown(): void
    {
        $this->logger = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        foreach (glob($this->root.'/outbox/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root.'/outbox');
        @unlink($this->root.'/install-id');
        @rmdir($this->root);
        Telemetry::resetCache();
        Setting::clearRuntimeSettings();
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);

        parent::tearDown();
    }

    private function account(string $template = 'dind'): void
    {
        $this->makeUser($this->username, [
            'template' => $template,
            'deploy_strategy' => 'static',
            'deploy_runtime' => 'nginx',
            'deploy_source' => 'git',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function spooled(): array
    {
        return array_column(Telemetry::spool()->pending(25, PHP_INT_MAX), 'report');
    }

    /**
     * @return array<string, mixed>
     */
    private function failingChecks(string $serving): array
    {
        return [
            AppHealth::DETAIL_SERVING => $serving,
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'placeholder', 'severity' => HealthCheck::SEVERITY_ERROR, 'message' => 'The site serves a placeholder.'],
            ],
        ];
    }

    public function test_a_health_finding_is_queued_as_a_partial_health_report_named_by_what_is_served(): void
    {
        $this->account();

        Telemetry::captureHealth($this->username, $this->failingChecks('placeholder'));

        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);
        $this->assertSame(Telemetry::KIND_HEALTH, $spooled[0]['kind']);
        $this->assertSame(DeployReport::OUTCOME_PARTIAL, $spooled[0]['outcome']);
        $this->assertSame('health-placeholder', $spooled[0]['failure']['rule']);
        $this->assertSame(Telemetry::EVENT_HEALTH, Telemetry::envelope($spooled[0])['type']);
    }

    public function test_a_health_finding_with_nothing_served_is_named_degraded(): void
    {
        $this->account();

        Telemetry::captureHealth($this->username, [AppHealth::DETAIL_SERVING => '']);

        $this->assertSame('health-degraded', $this->spooled()[0]['failure']['rule']);
    }

    public function test_a_health_finding_with_telemetry_off_queues_nothing(): void
    {
        config(['telemetry.enabled' => false]);
        $this->account();

        Telemetry::captureHealth($this->username, $this->failingChecks('placeholder'));

        $this->assertSame([], $this->spooled());
    }

    public function test_a_health_finding_on_a_classic_account_queues_nothing(): void
    {
        $this->account('default');

        Telemetry::captureHealth($this->username, $this->failingChecks('placeholder'));

        $this->assertSame([], $this->spooled());
    }

    public function test_a_signal_is_queued_as_a_recovery_named_by_its_slug(): void
    {
        $this->account();

        Telemetry::signal($this->username, 'port-realigned', 'Port 3000 answered, not 8080');

        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);
        $this->assertSame(DeployReport::OUTCOME_RECOVERED, $spooled[0]['outcome']);
        $this->assertSame('port-realigned', $spooled[0]['failure']['rule']);
        $this->assertSame([], $spooled[0]['log_tail']);
    }

    public function test_a_malformed_signal_slug_is_recorded_nowhere(): void
    {
        $this->account();

        Telemetry::signal($this->username, 'Port Realigned!');

        $this->assertSame([], $this->spooled());
    }

    public function test_a_signal_with_telemetry_off_or_on_a_classic_account_is_not_queued(): void
    {
        $this->account('default');
        Telemetry::signal($this->username, 'port-realigned');
        $this->assertSame([], $this->spooled());

        config(['telemetry.enabled' => false]);
        Telemetry::signal($this->username, 'port-realigned');
        $this->assertSame([], $this->spooled());
    }

    public function test_a_signal_that_fails_to_build_is_swallowed(): void
    {
        $this->account();
        Schema::drop('users');

        Telemetry::signal($this->username, 'port-realigned');

        $this->assertSame([], $this->spooled());
    }

    public function test_a_partial_deploy_failing_an_error_check_is_grouped_by_what_it_serves(): void
    {
        $this->makeUser($this->username, [
            'template' => 'dind',
            'deploy_strategy' => 'static',
            'deploy_runtime' => 'nginx',
        ] + $this->failingChecks('default-page'));
        $this->logger = DeployLogger::start($this->username);
        Http::fake();

        Telemetry::captureDeploy($this->logger, $this->username, 'partial', 'Health check failed', ['id' => 'd1', 'status' => 'partial', 'stage' => 'running']);

        $this->assertSame('health-default-page', $this->spooled()[0]['failure']['rule']);
        Http::assertNothingSent();
    }

    public function test_a_spool_that_cannot_be_opened_never_reaches_the_caller(): void
    {
        $this->account();
        $this->logger = DeployLogger::start($this->username);
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, null);
        config(['telemetry.spool_dir' => ['not', 'a', 'path']]);
        Log::spy();

        Telemetry::signal($this->username, 'port-realigned');
        Telemetry::captureHealth($this->username, $this->failingChecks('placeholder'));
        $fix = Telemetry::captureDeploy($this->logger, $this->username, 'success', null, ['id' => 'd1', 'status' => 'success', 'stage' => 'running']);

        $this->assertNull($fix);
        Log::shouldHaveReceived('debug')->withArgs(fn (string $m): bool => str_starts_with($m, 'Telemetry signal failed: '))->once();
        Log::shouldHaveReceived('debug')->withArgs(fn (string $m): bool => str_starts_with($m, 'Telemetry health capture failed: '))->once();
        Log::shouldHaveReceived('debug')->withArgs(fn (string $m): bool => str_starts_with($m, 'Telemetry capture failed: '))->once();
    }

    public function test_a_bundle_candidate_whose_spool_cannot_be_made_is_sent_without_a_bundle(): void
    {
        config(['telemetry.source_bundle.mode' => 'failed']);
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool('/proc/pa-no-such-spool/outbox', new RecordingRunner));
        $this->account();
        $this->logger = DeployLogger::start($this->username);
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        Telemetry::captureDeploy($this->logger, $this->username, 'failed', 'Build failed: exit code 1', ['id' => 'd1', 'status' => 'failed', 'stage' => 'running']);

        Http::assertSent(fn ($request): bool => ! array_key_exists('source_bundle', $request->data()['events'][0]['payload']));
    }

    public function test_a_bug_report_attaches_the_deploy_log_and_no_health_for_a_classic_account(): void
    {
        $this->account('default');
        $this->logger = DeployLogger::start($this->username);
        $this->logger->info('Cloning the repository');

        $result = Telemetry::captureBugReport([
            'project' => $this->username,
            'title' => 'The site is blank',
            'description' => 'It deploys and shows nothing.',
            'dry_run' => true,
        ]);

        $this->assertSame('preview', $result['status']);
        $this->assertStringContainsString('Cloning the repository', implode("\n", $result['report']['log_tail'] ?? []));
        $this->assertNull($result['report']['health'] ?? null);
        $this->assertSame(Telemetry::EVENT_BUG_REPORT, Telemetry::envelope($result['report'])['type']);
    }

    public function test_a_bug_report_whose_project_cannot_be_looked_up_fails_saying_so(): void
    {
        $this->account();
        Schema::drop('users');

        $result = Telemetry::captureBugReport([
            'project' => $this->username,
            'title' => 'Broken',
            'description' => 'Broken.',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertFalse($result['queued']);
        $this->assertStringStartsWith('Could not look the project up: ', (string) $result['reason']);
    }

    public function test_outside_the_test_environment_the_stored_switch_decides_and_a_signal_reaches_the_local_log(): void
    {
        $this->account();
        Setting::setRuntimeSettings(['telemetry_enabled' => '1']);
        $channel = Mockery::mock();
        $channel->shouldReceive('info')->once()->withArgs(
            fn (string $message, array $event): bool => $message === Telemetry::EVENT_RECOVERED.' recovered'
                && $event['type'] === Telemetry::EVENT_RECOVERED
                && $event['payload']['failure']['rule'] === 'port-realigned'
        );
        Log::spy();
        Log::shouldReceive('channel')->with('telemetry')->andReturn($channel);
        $env = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $enabled = Telemetry::enabled();
            Telemetry::signal($this->username, 'port-realigned');
        } finally {
            $this->app['env'] = $env;
        }

        $this->assertTrue($enabled);
        $this->assertCount(1, $this->spooled());
    }

    public function test_outside_the_test_environment_a_local_log_that_throws_costs_the_report_nothing(): void
    {
        $this->account();
        Setting::setRuntimeSettings(['telemetry_enabled' => '1']);
        Log::spy();
        Log::shouldReceive('channel')->with('telemetry')->andThrow(new \RuntimeException('log unwritable'));
        $env = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            Telemetry::signal($this->username, 'port-realigned');
        } finally {
            $this->app['env'] = $env;
        }

        $this->assertCount(1, $this->spooled());
        Log::shouldHaveReceived('debug')->with('Telemetry local record failed: log unwritable');
    }
}
