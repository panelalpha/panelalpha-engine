<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\System\Project\Dind\AppHealth;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use PHPUnit\Framework\TestCase;

/**
 * A periodic health finding, as it leaves the engine.
 *
 * The thing worth asserting is that it is not an install. An application that
 * deployed successfully six hours ago and is now serving the engine's own
 * placeholder has not failed to install — it installed, and then stopped
 * serving — so reporting it under `project.install.` would land it in every
 * query that counts installs and would make the failure rate a number about
 * something else.
 */
class HealthTelemetryTest extends TestCase
{
    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function report(array $details, string $kind = Telemetry::KIND_HEALTH): array
    {
        $report = DeployReport::build([
            'id' => '01J000000000000000000000',
            'occurred_at' => 1757000000,
            'outcome' => DeployReport::OUTCOME_PARTIAL,
            'tier' => DeployReport::TIER_METADATA,
            'install_id' => 'install-1',
            'username' => 'shop',
            'latest' => [],
            'details' => $details,
            'error' => '',
            'signal' => 'health-placeholder',
        ]);
        $report['kind'] = $kind;

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function details(): array
    {
        return [
            'deploy_strategy' => 'static',
            'deploy_runtime' => 'nginx',
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => true,
            AppHealth::DETAIL_SERVING => 'placeholder',
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'not-placeholder', 'severity' => 'error', 'message' => 'Serving our own page.'],
                ['id' => 'no-welcome-page', 'severity' => 'warning', 'message' => 'Still the welcome page.'],
            ],
        ];
    }

    /**
     * The deploy that *first* notices a degraded application must fingerprint
     * on the check, not on the sentence the check produced. The message
     * carries the explainer's project-specific detail -- which index.php was
     * found where -- so signing on it gave the same failing check as many
     * fingerprints as there were project layouts.
     */
    public function test_a_partial_from_a_failing_check_is_signed_by_the_check(): void
    {
        $details = $this->details();

        $report = DeployReport::build([
            'id' => '01J000000000000000000000',
            'occurred_at' => 1757000000,
            'outcome' => DeployReport::OUTCOME_PARTIAL,
            'tier' => DeployReport::TIER_METADATA,
            'install_id' => 'install-1',
            'username' => 'shop',
            'latest' => [],
            'details' => $details,
            'error' => 'The front page is missing: / answered 403. There is no index.php in ~/project.',
            'signal' => 'health-placeholder',
        ]);

        $this->assertSame('health-placeholder', $report['failure']['rule']);
        $this->assertSame('health-placeholder', $report['failure']['signature']);
        $this->assertTrue($report['failure']['explained']);

        // The same check on a project whose explainer said something else is
        // the same problem, and must land on the same fingerprint.
        $other = DeployReport::build([
            'id' => '01J000000000000000000001',
            'occurred_at' => 1757000900,
            'outcome' => DeployReport::OUTCOME_PARTIAL,
            'tier' => DeployReport::TIER_METADATA,
            'install_id' => 'install-1',
            'username' => 'shop',
            'latest' => [],
            'details' => $details,
            'error' => 'The front page is missing: / answered 403. The entry point is public/index.php.',
            'signal' => 'health-placeholder',
        ]);

        $this->assertSame($report['fingerprint'], $other['fingerprint']);
    }

    public function test_a_health_finding_is_not_an_install_event(): void
    {
        $envelope = Telemetry::envelope($this->report($this->details()));

        $this->assertSame(Telemetry::EVENT_HEALTH, $envelope['type']);
        $this->assertStringStartsNotWith('project.install', $envelope['type']);
    }

    public function test_the_same_report_without_the_kind_is_still_a_deploy(): void
    {
        $envelope = Telemetry::envelope($this->report($this->details(), 'deploy'));

        $this->assertSame(Telemetry::EVENT_PARTIAL, $envelope['type']);
    }

    /**
     * `success` marks a failed deploy by the ingest spec, and this deploy did
     * not fail — it succeeded and the application stopped serving afterwards.
     * Carrying the field would answer a question nobody asked here.
     */
    public function test_a_health_finding_carries_no_deploy_success_flag(): void
    {
        $payload = Telemetry::envelope($this->report($this->details()))['payload'];

        $this->assertArrayNotHasKey('success', $payload);
        $this->assertSame('health_check', $payload['software_op']);
        $this->assertSame('placeholder', $payload['serving']);
    }

    /** One error among warnings is an error: an alert binds to the worst. */
    public function test_the_severity_is_the_worst_check_that_failed(): void
    {
        $payload = Telemetry::envelope($this->report($this->details()))['payload'];

        $this->assertSame('error', $payload['severity']);
    }

    public function test_warnings_alone_report_as_a_warning(): void
    {
        $details = $this->details();
        $details[AppHealth::DETAIL_CHECKS] = [
            ['id' => 'no-welcome-page', 'severity' => 'warning', 'message' => 'x'],
        ];

        $payload = Telemetry::envelope($this->report($details))['payload'];

        $this->assertSame('warning', $payload['severity']);
    }

    /**
     * The check ids travel and the messages do not. An id is a stable slug
     * that makes one failure countable across installs; a message names the
     * customer's own files — which page is missing, which host was rejected.
     */
    public function test_the_report_carries_check_ids_and_never_their_messages(): void
    {
        $report = $this->report($this->details());

        $this->assertSame(
            [
                ['id' => 'not-placeholder', 'severity' => 'error'],
                ['id' => 'no-welcome-page', 'severity' => 'warning'],
            ],
            $report['health']['failed']
        );
        $this->assertSame('placeholder', $report['health']['serving']);
        $this->assertStringNotContainsString('Serving our own page', json_encode($report));
    }

    /** An account whose checks all passed carries no `failed` key at all. */
    public function test_a_healthy_account_reports_no_failed_checks(): void
    {
        $details = $this->details();
        $details[AppHealth::DETAIL_SERVING] = 'ok';
        $details[AppHealth::DETAIL_CHECKS] = [];

        $health = $this->report($details)['health'];

        $this->assertArrayNotHasKey('failed', $health);
        $this->assertSame('ok', $health['serving']);
    }

    /**
     * The verdict rides along on ordinary deploy reports too, so the state an
     * install finished in is visible without waiting for the next sweep.
     */
    public function test_a_deploy_report_carries_the_verdict_as_well(): void
    {
        $health = $this->report($this->details(), 'deploy')['health'];

        $this->assertSame('placeholder', $health['serving']);
        $this->assertCount(2, $health['failed']);
    }

    public function test_partial_is_a_reportable_outcome(): void
    {
        $this->assertTrue(DeployReport::isReportable(DeployReport::OUTCOME_PARTIAL));
    }

    /**
     * The domain a visitor types can fail while every port answers inside the
     * container — the reverse proxy serving another customer's site, a vhost
     * that never loaded. `AppHealth::reachability()` has always decided this
     * and nothing carried the verdict out of the account.
     */
    public function test_the_health_block_carries_the_reachability_verdict(): void
    {
        $details = $this->details();
        $details[AppHealth::DETAIL_REACHABLE] = AppHealth::REACH_DIFFERS;

        $this->assertSame(AppHealth::REACH_DIFFERS, $this->report($details)['health']['reachable']);
    }

    public function test_a_probe_that_made_no_reachability_verdict_says_nothing(): void
    {
        $this->assertArrayNotHasKey('reachable', $this->report($this->details())['health']);
    }

    /**
     * A health finding is the one event about an application nobody is
     * watching deploy, so the name it is served under is the first thing a
     * reader wants and the last thing that was there.
     */
    public function test_a_health_finding_names_the_application_it_is_about(): void
    {
        $report = DeployReport::build([
            'id' => '01J000000000000000000000',
            'occurred_at' => 1757000000,
            'outcome' => DeployReport::OUTCOME_PARTIAL,
            'tier' => DeployReport::TIER_METADATA,
            'install_id' => 'install-1',
            'username' => 'shop',
            'latest' => [],
            'details' => $this->details(),
            'error' => '',
            'signal' => 'health-placeholder',
            'domains' => [
                ['domain' => 'shop.acme.com', 'primary' => true, 'type' => 'main'],
                ['domain' => 'shop-4f2a.panelalpha.online', 'tunnel' => 'panelalpha'],
            ],
        ]);

        $this->assertSame('shop.acme.com', $report['domain']['primary']);
        $this->assertSame('shop-4f2a.panelalpha.online', $report['domain']['names'][1]['domain']);
    }
}
