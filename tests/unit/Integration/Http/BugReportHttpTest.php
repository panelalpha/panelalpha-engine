<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Mcp\Tools\Api\ApiTool;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/** POST /bug-reports: a report is queued for the monitoring host, and each reason it cannot be has its own status. */
class BugReportHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->root = sys_get_temp_dir() . '/pa-bug-http-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/outbox', 0777, true);
        file_put_contents($this->root . '/install-id', str_repeat('b', 32));
        config([
            'telemetry.enabled' => true,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->root . '/outbox',
            'telemetry.pin_file' => $this->root . '/install-id',
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
        ]);
        Telemetry::resetCache();
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($this->root . '/outbox', new RecordingRunner()));
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        $this->makeUser('shop', ['deploy_strategy' => 'static', 'deploy_runtime' => 'nginx']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Telemetry::resetCache();
        parent::tearDown();
    }

    public function test_a_report_is_queued_and_labelled_with_the_surface_that_filed_it(): void
    {
        $response = $this->postJson('/api/bug-reports', $this->report(), [ApiTool::VIA_HEADER => ApiTool::VIA_MCP]);

        $response->assertCreated()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.endpoint', 'https://monitoring.test/api/v1/events');
        $this->assertNotEmpty($response->json('data.id'));
        $this->assertCount(1, glob($this->root . '/outbox/*') ?: []);
    }

    public function test_a_report_about_an_unknown_project_is_not_found(): void
    {
        $this->postJson('/api/bug-reports', $this->report(['project' => 'nobody']))->assertNotFound();
    }

    public function test_a_report_while_telemetry_is_off_is_a_conflict(): void
    {
        config(['telemetry.enabled' => false]);
        Telemetry::resetCache();

        $this->postJson('/api/bug-reports', $this->report())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Telemetry is disabled on this install (TELEMETRY_ENABLED=false), so a bug report would never be sent. Enable it, or send storage/logs/telemetry-*.log to support instead.');
    }

    public function test_a_report_without_a_monitoring_host_is_unavailable(): void
    {
        config(['monitoring.url' => '']);
        Telemetry::resetCache();

        $this->postJson('/api/bug-reports', $this->report())->assertStatus(503);
    }

    public function test_a_report_without_its_required_fields_is_refused(): void
    {
        $this->postJson('/api/bug-reports', ['title' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project', 'title', 'description']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        return $overrides + [
            'project' => 'shop',
            'title' => 'Deploys succeed but the site answers 502',
            'description' => 'It finishes green and then 502s.',
            'severity' => 'high',
            'area' => 'deploy',
            'attach_log' => false,
            'attach_health' => false,
        ];
    }
}
