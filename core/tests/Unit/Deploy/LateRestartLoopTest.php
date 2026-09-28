<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * engine#90: a container that answers once and then keeps restarting.
 *
 * MintHCM (CMD `start.sh; exec bash`, no TTY) answered 200, exited 0 a second
 * later and restarted 11 times in three minutes behind a 502, while the deploy
 * said "Deploy finished successfully". Rows are the engine's inspect format as
 * the test host printed them for that container.
 */
class LateRestartLoopTest extends TestCase
{
    private const JUST_STARTED = '{"name":"/project-app-1","service":"app","state":"running","exit":0,"restarts":0}';

    public function test_a_restart_between_the_snapshots_is_a_loop_even_on_exit_0(): void
    {
        $after = '{"name":"/project-app-1","service":"app","state":"running","exit":0,"restarts":1}';

        $check = AppHealth::restartLoopBetween(self::JUST_STARTED, $after);

        $this->assertNotNull($check, 'running again after a clean exit is still a restart');
        $this->assertSame(AppHealth::CHECK_RESTART_LOOPING, $check['id']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity'], 'error severity is what makes the deploy partial');
        $this->assertStringContainsString('app (running, last exit 0, restarted 1 time)', $check['detail']);
    }

    public function test_the_state_the_host_actually_reported_is_a_loop(): void
    {
        $after = '{"name":"/project-app-1","service":"app","state":"restarting","exit":0,"restarts":10}';

        $check = AppHealth::restartLoopBetween(self::JUST_STARTED, $after);

        $this->assertNotNull($check);
        $this->assertStringContainsString('restarted 10 times', $check['detail']);
    }

    public function test_restarting_is_a_loop_without_an_earlier_snapshot(): void
    {
        $after = '{"name":"/project-app-1","service":"app","state":"restarting","exit":0,"restarts":3}';

        $this->assertNotNull(AppHealth::restartLoopBetween(null, $after));
    }

    public function test_a_healthy_stack_is_not_a_loop(): void
    {
        $before = self::JUST_STARTED . "\n"
            . '{"name":"/project-db-1","service":"db","state":"running","exit":0,"restarts":0}';
        $after = $before;

        $this->assertNull(AppHealth::restartLoopBetween($before, $after));
    }

    /** Restarts a kept sidecar collected in an older deploy say nothing about this one. */
    public function test_old_restarts_count_only_when_they_grow(): void
    {
        $db = '{"name":"/project-db-1","service":"db","state":"running","exit":0,"restarts":5}';

        $this->assertNull(AppHealth::restartLoopBetween($db, $db));
        $this->assertNull(AppHealth::restartLoopBetween(null, $db), 'no baseline, no count comparison');
    }

    public function test_a_container_missing_from_the_first_snapshot_is_compared_against_zero(): void
    {
        $after = '{"name":"/project-worker-1","service":"worker","state":"running","exit":0,"restarts":2}';

        $check = AppHealth::restartLoopBetween(self::JUST_STARTED, $after);

        $this->assertNotNull($check);
        $this->assertStringContainsString('worker', $check['detail']);
    }

    public function test_a_finished_one_shot_is_not_a_loop(): void
    {
        $ready = '{"name":"/project-ready-1","service":"ready","state":"exited","exit":0,"restarts":0}';

        $this->assertNull(AppHealth::restartLoopBetween($ready, $ready));
    }

    public function test_unreadable_output_is_not_a_loop(): void
    {
        $this->assertNull(AppHealth::restartLoopBetween(null, ''));
        $this->assertNull(AppHealth::restartLoopBetween('garbage', "not json\n{}"));
    }

    /** The flattened check is what servingWarnings() turns into a partial deploy. */
    public function test_the_check_makes_the_deploy_not_clean(): void
    {
        $after = '{"name":"/project-app-1","service":"app","state":"restarting","exit":0,"restarts":4}';
        $check = AppHealth::restartLoopBetween(self::JUST_STARTED, $after);

        $warnings = AppHealth::servingWarnings([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => true,
            AppHealth::DETAIL_PORTS => [['port' => 8080, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]],
            AppHealth::DETAIL_CHECKS => [[
                'id' => $check['id'],
                'severity' => $check['severity'],
                'message' => $check['title'] . ' ' . $check['detail'],
            ]],
        ]);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('restarting, not running', $warnings[0]);
    }
}
