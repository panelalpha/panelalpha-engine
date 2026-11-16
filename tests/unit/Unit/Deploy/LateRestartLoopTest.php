<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * A container that answers once and then keeps restarting.
 *
 * MintHCM (CMD `start.sh; exec bash`, no TTY) answered 200, exited 0 a second
 * later and restarted 11 times in three minutes behind a 502, while the deploy
 * said "Deploy finished successfully". Snapshots are the account daemon's
 * inspect of each container, the fields the engine reads of that container.
 */
class LateRestartLoopTest extends TestCase
{
    private const JUST_STARTED = [
        'Name' => '/project-app-1',
        'Config' => ['Labels' => ['com.docker.compose.service' => 'app']],
        'State' => ['Status' => 'running', 'ExitCode' => 0],
        'RestartCount' => 0,
    ];

    public function test_a_restart_between_the_snapshots_is_a_loop_even_on_exit_0(): void
    {
        $after = [self::container('/project-app-1', 'app', 'running', 0, 1)];

        $check = AppHealth::restartLoopBetween([self::JUST_STARTED], $after);

        $this->assertNotNull($check, 'running again after a clean exit is still a restart');
        $this->assertSame(AppHealth::CHECK_RESTART_LOOPING, $check['id']);
        $this->assertSame(CheckResult::STATUS_FAIL, $check['status']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity'], 'error severity is what makes the deploy partial');
        // Running again: Docker has reset ExitCode to 0, which is not the crash's.
        $this->assertStringContainsString('app (running, last exit unknown, restarted 1 time)', $check['detail']);
    }

    public function test_the_exit_code_is_quoted_while_the_container_is_down(): void
    {
        $after = [self::container('/project-app-1', 'app', 'restarting', 1, 4)];

        $check = AppHealth::restartLoopBetween([self::JUST_STARTED], $after);

        $this->assertStringContainsString('app (restarting, last exit 1, restarted 4 times)', $check['detail']);
    }

    public function test_a_running_container_never_reads_as_a_clean_exit(): void
    {
        $after = [self::container('/project-app-1', 'app', 'running', 0, 3)];

        $check = AppHealth::restartLoopBetween([self::JUST_STARTED], $after);

        $this->assertStringNotContainsString('last exit 0', $check['detail']);
    }

    public function test_the_state_the_host_actually_reported_is_a_loop(): void
    {
        $after = [self::container('/project-app-1', 'app', 'restarting', 0, 10)];

        $check = AppHealth::restartLoopBetween([self::JUST_STARTED], $after);

        $this->assertNotNull($check);
        $this->assertStringContainsString('restarted 10 times', $check['detail']);
    }

    public function test_restarting_is_a_loop_without_an_earlier_snapshot(): void
    {
        $after = [self::container('/project-app-1', 'app', 'restarting', 0, 3)];

        $this->assertNotNull(AppHealth::restartLoopBetween(null, $after));
    }

    public function test_a_healthy_stack_is_not_a_loop(): void
    {
        $before = [self::JUST_STARTED, self::container('/project-db-1', 'db', 'running', 0, 0)];
        $after = $before;

        $this->assertNull(AppHealth::restartLoopBetween($before, $after));
    }

    /** Restarts a kept sidecar collected in an older deploy say nothing about this one. */
    public function test_old_restarts_count_only_when_they_grow(): void
    {
        $db = [self::container('/project-db-1', 'db', 'running', 0, 5)];

        $this->assertNull(AppHealth::restartLoopBetween($db, $db));
        $this->assertNull(AppHealth::restartLoopBetween(null, $db), 'no baseline, no count comparison');
    }

    public function test_a_container_missing_from_the_first_snapshot_is_compared_against_zero(): void
    {
        $after = [self::container('/project-worker-1', 'worker', 'running', 0, 2)];

        $check = AppHealth::restartLoopBetween([self::JUST_STARTED], $after);

        $this->assertNotNull($check);
        $this->assertStringContainsString('worker', $check['detail']);
    }

    public function test_a_finished_one_shot_is_not_a_loop(): void
    {
        $ready = [self::container('/project-ready-1', 'ready', 'exited', 0, 0)];

        $this->assertNull(AppHealth::restartLoopBetween($ready, $ready));
    }

    public function test_unreadable_output_is_not_a_loop(): void
    {
        $this->assertNull(AppHealth::restartLoopBetween(null, []));
        $this->assertNull(AppHealth::restartLoopBetween([null, 'garbage'], [[], ['Name' => 7, 'State' => ['Status' => 'restarting']]]));
    }

    /** Only the sidecar loops, so the verdict names it, not the application. */
    public function test_a_looping_sidecar_beside_a_steady_app_is_named_as_the_sidecar(): void
    {
        $before = [self::JUST_STARTED, self::container('/project-db-1', 'db', 'running', 0, 0)];
        $after = [self::JUST_STARTED, self::container('/project-db-1', 'db', 'restarting', 1, 4)];

        $check = AppHealth::restartLoopBetween($before, $after, ['app']);

        $this->assertSame(AppHealth::CHECK_RESTART_LOOPING, $check['id']);
        $this->assertSame(HealthCheck::SEVERITY_ERROR, $check['severity'], 'still partial, not a failed deploy');
        $this->assertSame('A backing service is restarting: db (restarting, last exit 1, restarted 4 times).', $check['title']);
        $this->assertStringNotContainsString('application is restarting', $check['title'] . $check['detail']);
        $this->assertStringContainsString('db', $check['fix']);
    }

    public function test_the_app_looping_with_a_sidecar_is_still_the_application(): void
    {
        $after = [
            self::container('/project-app-1', 'app', 'restarting', 1, 4),
            self::container('/project-db-1', 'db', 'restarting', 1, 4),
        ];

        $this->assertSame('The application is restarting, not running.', AppHealth::restartLoopBetween([self::JUST_STARTED], $after, ['app'])['title']);
        // Not knowing which service is the app keeps the old verdict.
        $db = [self::container('/project-db-1', 'db', 'restarting', 1, 4)];
        $this->assertSame('The application is restarting, not running.', AppHealth::restartLoopBetween(null, $db)['title']);
    }

    /** The flattened check is what servingWarnings() turns into a partial deploy. */
    public function test_the_check_makes_the_deploy_not_clean(): void
    {
        $after = [self::container('/project-app-1', 'app', 'restarting', 0, 4)];
        $check = AppHealth::restartLoopBetween([self::JUST_STARTED], $after);

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
        $this->assertStringContainsString('restarting, not running', $warnings[0]->message);
    }

    /** @return array<string, mixed> the account daemon's inspect of a container */
    private static function container(string $name, string $service, string $status, int $exitCode, int $restarts): array
    {
        return [
            'Name' => $name,
            'Config' => ['Labels' => ['com.docker.compose.service' => $service]],
            'State' => ['Status' => $status, 'ExitCode' => $exitCode],
            'RestartCount' => $restarts,
        ];
    }
}
