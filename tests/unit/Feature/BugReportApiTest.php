<?php

namespace Tests\Feature;

use App\Lib\Deploy\Telemetry\Telemetry;
use Tests\TestCase;

/**
 * `POST /bug-reports` over HTTP.
 *
 * The capture itself is covered by {@see BugReportCaptureTest}; what is left
 * here is the part only the endpoint decides — that a refusal comes back as a
 * status a client can branch on rather than as a 200 with a sad message in it,
 * and that a report filed against a real account really does arrive carrying
 * that account's inspection.
 *
 * Runs against the dind account AppUsersCreateUserTest deploys, because a bug
 * report needs a real application to inspect. Skipped with it when GIT_REPO is
 * not set.
 *
 * @depends Tests\Feature\AppUsersCreateUserTest::test_create_git_repo_user
 */
class BugReportApiTest extends TestCase
{
    private string $spoolDir;

    private string $pinFile;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir() . '/pa-bug-api-' . bin2hex(random_bytes(6));
        $this->spoolDir = $root . '/outbox';
        $this->pinFile = $root . '/install-id';
        mkdir($root, 0777, true);
        file_put_contents($this->pinFile, str_repeat('a', 32));

        config([
            'telemetry.enabled' => true,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.spool_dir' => $this->spoolDir,
            'telemetry.pin_file' => $this->pinFile,
            'monitoring.url' => 'https://monitoring.test',
        ]);
        Telemetry::resetCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
        @unlink($this->pinFile);
        @rmdir(dirname($this->spoolDir));
        Telemetry::resetCache();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'project' => $this->project(),
            'title' => 'Next.js deploys succeed but the site answers 502',
            'description' => 'It finishes green and then 502s until I restart the container.',
        ];
    }

    /** The deployed account the suite created, or nothing to test against. */
    private function project(): string
    {
        if (empty(env('GIT_REPO'))) {
            $this->markTestSkipped('GIT_REPO env var is not set');
        }

        return $this->getCacheAsString('git_repo_user.username');
    }

    public function test_it_queues_a_report_and_hands_back_the_bytes(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/bug-reports', $this->payload() + [
            'severity' => 'high',
            'area' => 'deploy',
            // The probe touches the account container and costs seconds; the
            // inspection below is what this test is actually about.
            'attach_health' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.queued', true);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.report.kind', 'bug');
        $response->assertJsonPath('data.report.bug.severity', 'high');
        // The account is named by a salted hash and never by its username.
        $response->assertJsonMissingPath('data.report.username');
        // 201 is "queued", not "delivered" — the shipper sends it later.
        $this->assertCount(1, glob($this->spoolDir . '/*.json') ?: []);
    }

    public function test_it_gathers_the_evidence_rather_than_asking_for_it(): void
    {
        // What separates this from a support email: the engine goes and looks
        // at the application instead of taking the reporter's word for it.
        $this->authenticate();

        $response = $this->postJson('/api/bug-reports', $this->payload());

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['report' => ['app' => ['inspect' => ['application', 'ports', 'environment']]]],
        ]);
    }

    public function test_the_surface_it_came_from_is_recorded(): void
    {
        $this->authenticate();

        $this->postJson('/api/bug-reports', $this->payload() + ['attach_health' => false])
            ->assertJsonPath('data.report.bug.via', 'api');
    }

    public function test_telemetry_being_off_is_a_conflict_and_not_a_success(): void
    {
        config(['telemetry.enabled' => false]);
        $this->authenticate();

        $response = $this->postJson('/api/bug-reports', $this->payload());

        // 409: a decision somebody made on this install, which no retry changes.
        $response->assertStatus(409);
        $this->assertSame([], glob($this->spoolDir . '/*.json') ?: []);
    }

    public function test_no_monitoring_host_is_a_not_yet(): void
    {
        config(['monitoring.url' => '']);
        $this->authenticate();

        $this->postJson('/api/bug-reports', $this->payload())->assertStatus(503);
    }

    public function test_a_report_must_name_a_project_a_title_and_a_body(): void
    {
        $this->authenticate();

        $this->postJson('/api/bug-reports', ['title' => 'too short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project', 'description']);
    }

    public function test_a_project_that_is_not_here_is_a_404(): void
    {
        $this->authenticate();

        $this->postJson('/api/bug-reports', $this->payload() + ['project' => 'no-such-project'])
            ->assertStatus(404);
    }

    public function test_an_unknown_area_is_normalised_rather_than_rejected(): void
    {
        // The panel gains features faster than an engine gets updated; a
        // report about an area this version has no name for is still a report.
        $this->authenticate();

        $this->postJson('/api/bug-reports', $this->payload() + [
            'area' => 'object storage',
            'attach_health' => false,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.report.bug.area', 'object-storage');
    }

    public function test_it_needs_a_token_like_everything_else(): void
    {
        // No project needed: auth is decided before the body is read.
        $this->postJson('/api/bug-reports', ['title' => 'x', 'description' => 'y'])
            ->assertStatus(401);
    }
}
