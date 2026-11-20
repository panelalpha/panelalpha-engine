<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/** The fix monitoring answers a failed deploy with lands in its log, and a version that is no release is never taken as one. */
class DeployLoggerKnownProblemTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir() . '/pa-known-problem-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/outbox', 0777, true);
        file_put_contents($this->root . '/install-id', str_repeat('b', 32));
        $this->username = 'kp' . bin2hex(random_bytes(4));

        config([
            'telemetry.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->root . '/outbox',
            'telemetry.pin_file' => $this->root . '/install-id',
            'telemetry.failure_timeout' => 5,
            'telemetry.source_bundle.mode' => 'off',
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
            'system.version' => 'dev-main',
        ]);
        Telemetry::resetCache();
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($this->root . '/outbox', new RecordingRunner));
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        foreach (glob($this->root . '/outbox/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/outbox');
        @unlink($this->root . '/install-id');
        @rmdir($this->root);
        Telemetry::resetCache();
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);

        parent::tearDown();
    }

    public function test_a_failed_deploy_writes_the_fix_and_its_version_when_the_engine_has_no_release_number(): void
    {
        $this->makeUser($this->username, ['template' => 'dind', 'deploy_strategy' => 'static', 'deploy_runtime' => 'nginx', 'deploy_source' => 'git']);
        Http::fake(['*' => Http::response([
            'accepted' => 1,
            'results' => [['index' => 0, 'problem' => [
                'title' => 'Out of disk',
                'body_why' => 'The build filled the disk.',
                'body_fix' => 'Free some space',
                'fixed_in_version' => '2.0.3',
            ]]],
        ], 200)]);
        $logger = DeployLogger::start($this->username);
        $logger->info('Building the application');

        $logger->finish(DeployLogger::STATUS_FAILED, 'Build failed: exit code 1');

        $lines = array_column($logger->read()['lines'], 'msg');
        $this->assertSame([
            'Building the application',
            'Deploy failed: Build failed: exit code 1',
            'Known problem: Out of disk',
            'Why:',
            'The build filled the disk.',
            'How to fix:',
            'Free some space',
            'Fixed in engine version 2.0.3',
        ], $lines);
        $this->assertSame('Out of disk', $logger->readLatest()['problem']['title'] ?? null);
    }
}
