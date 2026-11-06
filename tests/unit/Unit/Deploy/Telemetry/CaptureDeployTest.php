<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Domains\PublicUrl;
use App\System\Services\Webserver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/**
 * What captureDeploy() does with a finished deploy: sent at once, spooled, or
 * neither, and what it hands back.
 */
class CaptureDeployTest extends TestCase
{
    use InMemoryDatabase;

    private const INSTALL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $root;

    private string $username;

    private ?DeployLogger $logger = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir().'/pa-capture-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/outbox', 0777, true);
        file_put_contents($this->root.'/install-id', self::INSTALL_ID);
        $this->username = 'cap'.bin2hex(random_bytes(4));

        config([
            'telemetry.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->root.'/outbox',
            'telemetry.pin_file' => $this->root.'/install-id',
            'telemetry.failure_timeout' => 5,
            'telemetry.source_bundle.mode' => 'off',
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
            'system.version' => '9.9.9',
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
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);

        parent::tearDown();
    }

    private function account(string $template = 'dind'): DeployLogger
    {
        $this->makeUser($this->username, [
            'template' => $template,
            'deploy_strategy' => 'static',
            'deploy_runtime' => 'nginx',
            'deploy_source' => 'git',
        ]);
        $this->logger = DeployLogger::start($this->username);
        $this->logger->info('Building the application');

        return $this->logger;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function spooled(): array
    {
        return array_column(Telemetry::spool()->pending(25, PHP_INT_MAX), 'report');
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    private function capture(string $outcome, ?string $error = 'Build failed: exit code 1', array $latest = []): ?array
    {
        return Telemetry::captureDeploy(
            $this->logger ?? $this->account(),
            $this->username,
            $outcome,
            $error,
            $latest + ['id' => 'deploy-1', 'status' => $outcome, 'stage' => 'running']
        );
    }

    public function test_a_failed_deploy_is_sent_at_once_and_returns_the_fix(): void
    {
        $this->account();
        Http::fake(['*' => Http::response([
            'accepted' => 1,
            'results' => [['index' => 0, 'problem' => ['title' => 'Out of disk', 'body_fix' => 'Free some space']]],
        ], 200)]);

        $problem = $this->capture('failed');

        $this->assertSame([
            'title' => 'Out of disk',
            'status' => null,
            'rejected_reason' => null,
            'external_id' => null,
            'fixed_in_version' => null,
            'stacks_hint' => null,
            'body_why' => null,
            'body_fix' => 'Free some space',
            'body_log' => null,
            'body_engine' => null,
        ], $problem);
        $this->assertSame([], $this->spooled());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $event = $request->data()['events'][0];

            $this->assertSame('https://monitoring.test/api/v1/events', $request->url());
            $this->assertSame('project.install.fail', $event['type']);
            $this->assertSame('failed', $event['payload']['outcome']);
            $this->assertSame(self::INSTALL_ID, $event['payload']['install']['id']);
            $this->assertSame('deploy', $event['payload']['software_op']);
            $this->assertFalse($event['payload']['success']);
            $this->assertNotSame([], $event['payload']['log_tail'] ?? []);
            $this->assertArrayNotHasKey('source_bundle', $event['payload']);

            return true;
        });
    }

    public function test_a_partial_deploy_is_grouped_by_its_first_warning_and_carries_no_hints(): void
    {
        $this->account();
        Http::fake(['*' => Http::response('busy', 503)]);

        $this->capture('partial', 'https://shop.example.com is served a certificate browsers will refuse (self_signed).', [
            'warnings' => [['code' => PublicUrl::CERTIFICATE_REFUSED, 'message' => 'Refused.', 'domain' => 'shop.example.com']],
        ]);

        $report = $this->spooled()[0];
        $this->assertSame(PublicUrl::CERTIFICATE_REFUSED, $report['failure']['rule']);
        $this->assertSame(PublicUrl::CERTIFICATE_REFUSED, $report['failure']['signature']);
        $this->assertStringNotContainsString('"hints"', (string) json_encode($report));
    }

    public function test_a_failure_the_server_does_not_take_goes_to_the_spool(): void
    {
        $this->account();
        Http::fake(['*' => Http::response('busy', 503)]);

        $this->assertNull($this->capture('failed'));

        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);
        $this->assertSame('failed', $spooled[0]['outcome']);
        Http::assertSentCount(1);
    }

    public function test_a_success_is_spooled_and_not_sent_with_an_empty_log_tail(): void
    {
        $this->account();
        Http::fake();

        $this->assertNull($this->capture('success', null));

        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);
        $this->assertSame('success', $spooled[0]['outcome']);
        $this->assertSame([], $spooled[0]['log_tail']);
        Http::assertNothingSent();
    }

    public function test_a_bundle_candidate_waits_for_the_scheduler_with_its_bundle_described(): void
    {
        config(['telemetry.source_bundle.mode' => 'failed']);
        $this->account();
        Http::fake();

        $this->assertNull($this->capture('failed'));

        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);
        $this->assertSame(['available' => false, 'skipped' => 'empty'], $spooled[0]['source_bundle']);
        Http::assertNothingSent();
    }

    public function test_bundling_skips_an_outcome_that_is_not_a_failure(): void
    {
        config(['telemetry.source_bundle.mode' => 'failed']);
        $this->account();
        Http::fake();

        $this->assertNull($this->capture('partial', 'Health check failed'));

        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);
        $this->assertArrayNotHasKey('source_bundle', $spooled[0]);
        Http::assertNothingSent();
    }

    public function test_telemetry_off_sends_and_spools_nothing(): void
    {
        config(['telemetry.enabled' => false]);
        $this->account();
        Http::fake();

        $this->assertNull($this->capture('failed'));

        $this->assertSame([], $this->spooled());
        Http::assertNothingSent();
    }

    public function test_a_classic_account_sends_and_spools_nothing(): void
    {
        $this->account('default');
        Http::fake();

        $this->assertNull($this->capture('failed'));

        $this->assertSame([], $this->spooled());
        Http::assertNothingSent();
    }

    public function test_a_precheck_rejection_sends_and_spools_nothing(): void
    {
        $this->account();
        Http::fake();

        $this->assertNull($this->capture('failed', 'Not enough disk', [DeployLogger::PRECHECK_REJECTED => true]));

        $this->assertSame([], $this->spooled());
        Http::assertNothingSent();
    }

    public function test_a_cancelled_deploy_is_not_reportable(): void
    {
        $this->account();
        Http::fake();

        $this->assertNull($this->capture('cancelled'));

        $this->assertSame([], $this->spooled());
        Http::assertNothingSent();
    }
}
