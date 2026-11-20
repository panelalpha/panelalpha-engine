<?php

namespace Tests\Integration\Console\Telemetry;

use Tests\TestCase;

/** `telemetry:bug-report` files a report about one project into the spool, or says why it cannot. */
class TelemetryBugReportCommandTest extends TestCase
{
    use TelemetrySandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelemetrySandbox();
        $this->makeUser('shop');
    }

    protected function tearDown(): void
    {
        $this->tearDownTelemetrySandbox();
        parent::tearDown();
    }

    public function test_a_report_is_queued_and_summarised(): void
    {
        $this->artisan('telemetry:bug-report', [
            'project' => 'shop',
            '--title' => 'Site answers 502 after deploy',
            '--description' => 'The deploy is green and the site answers 502.',
            '--severity' => 'high',
            '--area' => 'deploy',
            '--contact' => 'ops@example.test',
            '--no-health' => true,
            '--no-log' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutput('Bug report queued.')
            ->expectsOutputToContain('high')
            ->expectsOutputToContain('ops@example.test')
            ->expectsOutputToContain('skipped (--no-health)')
            ->expectsOutputToContain('https://monitoring.test/api/v1/events')
            ->expectsOutputToContain('telemetry:ship delivers it on the next scheduled run')
            ->assertExitCode(0);

        $spooled = $this->spooledReports();
        $this->assertCount(1, $spooled);
        $report = json_decode((string) file_get_contents($spooled[0]), true);
        $this->assertSame('Site answers 502 after deploy', $report['report']['bug']['title'] ?? $report['bug']['title'] ?? null);
    }

    public function test_a_classic_project_has_no_health_probe_to_attach(): void
    {
        $this->artisan('telemetry:bug-report', [
            'project' => 'shop',
            '--title' => 'Broken',
            '--description' => 'It is broken.',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Gathering evidence from shop')
            ->expectsOutputToContain('not available')
            ->expectsOutputToContain('none recorded')
            ->assertExitCode(0);

        $this->assertCount(1, $this->spooledReports());
    }

    public function test_a_dry_run_prints_the_report_and_files_nothing(): void
    {
        $this->artisan('telemetry:bug-report', [
            'project' => 'shop',
            '--title' => 'Preview only',
            '--description' => 'Show me what would be sent.',
            '--no-health' => true,
            '--dry-run' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('"title": "Preview only"')
            ->expectsOutputToContain('Nothing was filed.')
            ->assertExitCode(0);

        $this->assertSame([], $this->spooledReports());
    }

    public function test_json_prints_the_result_and_answers_success_for_a_queued_report(): void
    {
        $this->artisan('telemetry:bug-report', [
            'project' => 'shop',
            '--title' => 'As JSON',
            '--description' => 'For a script.',
            '--no-health' => true,
            '--json' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('"queued": true')
            ->doesntExpectOutputToContain('Gathering evidence')
            ->assertExitCode(0);
    }

    public function test_json_answers_failure_for_a_refused_report(): void
    {
        $this->artisan('telemetry:bug-report', [
            'project' => 'nosuch',
            '--title' => 'Gone',
            '--description' => 'Not here.',
            '--json' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('"status": "no-project"')
            ->assertExitCode(1);
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->artisan('telemetry:bug-report', [
            'project' => 'nosuch',
            '--title' => 'Gone',
            '--description' => 'Not here.',
            '--no-health' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutput("No project named 'nosuch' on this install.")
            ->assertExitCode(1);

        $this->assertSame([], $this->spooledReports());
    }

    public function test_disabled_telemetry_says_where_the_switch_is(): void
    {
        config(['telemetry.enabled' => false]);

        $this->artisan('telemetry:bug-report', [
            'project' => 'shop',
            '--title' => 'Off',
            '--description' => 'Telemetry is off.',
            '--no-health' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Telemetry is disabled on this install')
            ->expectsOutputToContain('Set TELEMETRY_ENABLED=true (and TELEMETRY_BUG_REPORTS=true)')
            ->assertExitCode(1);
    }

    public function test_without_a_project_the_report_is_refused(): void
    {
        $this->artisan('telemetry:bug-report', ['--title' => 'T', '--description' => 'D', '--no-interaction' => true])
            ->expectsOutput('A bug report is about one application: name the project it concerns.')
            ->assertExitCode(1);
    }

    public function test_without_a_description_the_report_is_refused(): void
    {
        $this->artisan('telemetry:bug-report', ['project' => 'shop', '--title' => 'T', '--no-interaction' => true])
            ->expectsOutput('A bug report needs both a title and a description.')
            ->assertExitCode(1);
    }

    public function test_a_person_is_asked_for_the_project_title_and_description(): void
    {
        $this->artisan('telemetry:bug-report', ['--no-health' => true, '--no-log' => true])
            ->expectsQuestion('Which project is this about', 'shop')
            ->expectsQuestion('What is wrong, in one line', 'Asked for everything')
            ->expectsOutput('What happened, what you expected, and how to reproduce it.')
            ->expectsQuestion(' ', 'First line.')
            ->expectsQuestion(' ', 'Second line.')
            ->expectsQuestion(' ', '')
            ->expectsOutput('Bug report queued.')
            ->assertExitCode(0);

        $this->assertCount(1, $this->spooledReports());
        $this->assertStringContainsString('First line.\nSecond line.', (string) file_get_contents($this->spooledReports()[0]));
    }
}
