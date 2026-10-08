<?php

namespace Tests\Feature;

use App\Lib\Deploy\Telemetry\BugReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What filing a bug report does, and — more to the point — what it refuses.
 *
 * Every other entry point in this subsystem swallows its own failures, because
 * it is a side effect of work somebody else asked for. This one is the work: a
 * person is standing there waiting to hear whether their report will reach
 * anyone, so each refusal has to be an answer they can act on rather than a
 * quiet local write that looks like success.
 */
class BugReportCaptureTest extends TestCase
{
    private string $spoolDir;

    private string $pinFile;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir() . '/pa-bug-' . bin2hex(random_bytes(6));
        $this->spoolDir = $root . '/outbox';
        $this->pinFile = $root . '/install-id';
        mkdir($root, 0777, true);
        // Pinned rather than derived: deriving probes docker, and an id that
        // depends on the machine the suite runs on is not a fixture.
        file_put_contents($this->pinFile, str_repeat('a', 32));

        config([
            'telemetry.enabled' => true,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.tier' => 2,
            'telemetry.spool_dir' => $this->spoolDir,
            'telemetry.pin_file' => $this->pinFile,
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function file(array $overrides = []): array
    {
        return Telemetry::captureBugReport($overrides + [
            // Every bug report is about one application. There is no project
            // on this box, so the account lookup is what most of these assert
            // against -- the refusals below all happen before it.
            'project' => 'shop',
            'title' => 'Deploys succeed but the site answers 502',
            'description' => 'It finishes green and then 502s until I restart the container.',
        ]);
    }

    /** @return list<string> */
    private function spooled(): array
    {
        return glob($this->spoolDir . '/*.json') ?: [];
    }

    public function test_it_ships_in_the_same_batch_as_an_install_event(): void
    {
        // The whole design: no second endpoint, no second credential, no second
        // schedule. A queued bug report is a report the shipper will pick up,
        // and the shipper is what decides its event type.
        $report = BugReport::build([
            'id' => (string) Str::ulid(),
            'occurred_at' => time(),
            'tier' => 2,
            'install_id' => str_repeat('a', 32),
            'username' => 'shop',
            'title' => 'Deploys succeed but the site answers 502',
            'description' => 'It finishes green and then 502s until I restart it.',
        ]);
        Telemetry::spool()->put($report);

        $pending = Telemetry::spool()->pending(25, PHP_INT_MAX);

        $this->assertCount(1, $pending);
        $this->assertSame(Telemetry::EVENT_BUG_REPORT, Telemetry::envelope($pending[0]['report'])['type']);
    }

    public function test_telemetry_being_off_is_an_error_and_not_a_silent_local_write(): void
    {
        config(['telemetry.enabled' => false]);

        $result = $this->file();

        $this->assertSame('disabled', $result['status']);
        $this->assertFalse($result['queued']);
        $this->assertStringContainsString('TELEMETRY_ENABLED', (string) $result['reason']);
        // Nothing would ever ship it, so nothing was written to pretend it might.
        $this->assertSame([], $this->spooled());
    }

    public function test_bug_reports_can_be_switched_off_on_their_own(): void
    {
        // An install may be happy to send anonymous deploy statistics and still
        // not want a free-text field leaving the box.
        config(['telemetry.bug_reports.enabled' => false]);

        $result = $this->file();

        $this->assertSame('disabled', $result['status']);
        $this->assertSame([], $this->spooled());
    }

    public function test_no_monitoring_host_means_not_yet_rather_than_no(): void
    {
        config(['monitoring.url' => '']);

        $result = $this->file();

        $this->assertSame('not-ready', $result['status']);
        $this->assertSame([], $this->spooled());
    }

    public function test_a_report_with_nothing_in_it_is_refused(): void
    {
        $this->assertSame('invalid', $this->file(['title' => '   '])['status']);
        $this->assertSame('invalid', $this->file(['description' => ''])['status']);
        $this->assertSame([], $this->spooled());
    }

    public function test_a_report_must_name_the_application_it_is_about(): void
    {
        // The constraint the whole shape rests on: without a project there is
        // nothing to inspect and nothing to probe, and what is left is a
        // complaint rather than a reproduction.
        $result = $this->file(['project' => '  ']);

        $this->assertSame('invalid', $result['status']);
        $this->assertStringContainsString('one application', (string) $result['reason']);
        $this->assertSame([], $this->spooled());
    }

    public function test_a_project_that_is_not_here_is_a_404_and_not_a_report(): void
    {
        $this->requireDatabase();

        $result = $this->file(['project' => 'no-such-project']);

        $this->assertSame('no-project', $result['status']);
        $this->assertSame([], $this->spooled());
    }

    public function test_a_database_that_is_down_does_not_claim_the_project_is_gone(): void
    {
        // "No project named shop on this install" is both false and alarming
        // when the truth is that nothing could be looked up at all.
        $result = $this->file(['project' => 'shop']);

        $this->assertContains($result['status'], ['no-project', 'failed']);
        $this->assertSame([], $this->spooled());
    }

    /**
     * These two answers are only distinguishable where a project can actually
     * be looked up; without a database every name is equally absent.
     */
    private function requireDatabase(): void
    {
        try {
            User::query()->limit(1)->exists();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database available: ' . $e->getMessage());
        }
    }

    public function test_a_refusal_is_decided_before_anything_is_gathered(): void
    {
        // Order matters: inspecting a project and probing its container costs
        // seconds, and an install with telemetry switched off must not pay
        // them to be told no.
        config(['telemetry.enabled' => false]);

        $this->assertSame('disabled', $this->file(['project' => 'no-such-project'])['status']);
    }

    public function test_the_event_type_is_its_own_family_and_not_an_install(): void
    {
        // Counting installs is a prefix match on `project.install.`, so a bug
        // report filed under that prefix would inflate every install count.
        $envelope = Telemetry::envelope([
            'id' => '01JGXR8Q4M9ZK7W2N5T3V6B0AC',
            'occurred_at' => 1756137600,
            'kind' => BugReport::KIND,
            'outcome' => BugReport::OUTCOME,
            'bug' => ['severity' => 'high', 'area' => 'deploy'],
        ]);

        $this->assertSame('support.bug_report', $envelope['type']);
        $this->assertSame('bug_report', $envelope['payload']['software_op']);
        $this->assertSame('high', $envelope['payload']['severity']);
        // No `success`: there was no deploy, and a boolean saying one worked
        // would be a fabricated result.
        $this->assertArrayNotHasKey('success', $envelope['payload']);
    }

    public function test_an_install_event_is_untouched_by_any_of_this(): void
    {
        $envelope = Telemetry::envelope([
            'id' => '01JGXR8Q4M9ZK7W2N5T3V6B0AC',
            'occurred_at' => 1756137600,
            'outcome' => 'failed',
            'deploy' => ['source' => 'git'],
        ]);

        $this->assertSame('project.install.fail', $envelope['type']);
        $this->assertSame('deploy', $envelope['payload']['software_op']);
        $this->assertFalse($envelope['payload']['success']);
        $this->assertSame('git', $envelope['payload']['source']);
    }
}
