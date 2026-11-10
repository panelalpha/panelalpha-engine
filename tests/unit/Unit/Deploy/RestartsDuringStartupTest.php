<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Tests\TestCase;

/**
 * An app that crashes once or twice while its database starts, then serves, is not a
 * restart loop; only a restart that goes on is, whichever container won the race.
 */
class RestartsDuringStartupTest extends TestCase
{
    private const BEFORE = [[
        'Name' => '/project-app-1',
        'Config' => ['Labels' => ['com.docker.compose.service' => 'app']],
        'State' => ['Status' => 'running', 'ExitCode' => 0],
        'RestartCount' => 0,
    ]];

    private const RESTARTED_TWICE = [[
        'Name' => '/project-app-1',
        'Config' => ['Labels' => ['com.docker.compose.service' => 'app']],
        'State' => ['Status' => 'running', 'ExitCode' => 0],
        'RestartCount' => 2,
    ]];

    public function test_restarts_that_stopped_while_the_app_serves_are_not_a_loop(): void
    {
        $health = $this->health([self::BEFORE, self::RESTARTED_TWICE, self::RESTARTED_TWICE, self::RESTARTED_TWICE]);

        $report = $health->report();

        $this->assertNotContains(AppHealth::CHECK_RESTART_LOOPING, $this->checkIds($report));
        $this->assertSame(3, $health->settled, 'two more looks after the first, ten seconds of staying up');
    }

    public function test_a_restart_that_goes_on_is_still_a_loop(): void
    {
        $again = [['Name' => '/project-app-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'app']], 'State' => ['Status' => 'running', 'ExitCode' => 0], 'RestartCount' => 3]];
        $health = $this->health([self::BEFORE, self::RESTARTED_TWICE, $again]);

        $this->assertContains(AppHealth::CHECK_RESTART_LOOPING, $this->checkIds($health->report()));
    }

    public function test_a_restarted_container_that_is_down_on_a_later_look_is_a_loop(): void
    {
        $down = [['Name' => '/project-app-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'app']], 'State' => ['Status' => 'exited', 'ExitCode' => 1], 'RestartCount' => 2]];
        $health = $this->health([self::BEFORE, self::RESTARTED_TWICE, self::RESTARTED_TWICE, $down]);

        $this->assertContains(AppHealth::CHECK_RESTART_LOOPING, $this->checkIds($health->report()));
    }

    public function test_a_container_docker_is_restarting_is_a_loop_without_waiting_longer(): void
    {
        $restarting = [['Name' => '/project-app-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'app']], 'State' => ['Status' => 'restarting', 'ExitCode' => 1], 'RestartCount' => 2]];
        $health = $this->health([self::BEFORE, $restarting]);

        $this->assertContains(AppHealth::CHECK_RESTART_LOOPING, $this->checkIds($health->report()));
        $this->assertSame(1, $health->settled);
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @return list<string>
     */
    private function checkIds(?array $report): array
    {
        $this->assertNotNull($report);

        return array_map(static fn (array $check): string => (string) ($check['id'] ?? ''), (array) $report['checks']);
    }

    /** @param list<list<array<string, mixed>>> $snapshots one per inspect of the stack, in order */
    private function health(array $snapshots): AppHealth
    {
        $username = 'restarts'.bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        DeployLogger::start($username);

        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($username);
        $dind->method('userModel')->willReturn($user);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return new class ($dind, $snapshots) extends AppHealth {
            public int $settled = 0;

            /** @param list<list<array<string, mixed>>> $snapshots */
            public function __construct(Dind $dind, private array $snapshots)
            {
                parent::__construct($dind);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): ?array
            {
                return ['healthy' => true, 'ports' => [['port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK, 'http_code' => 200, 'time' => 0.1, 'detail' => 'HTTP 200']], 'checks' => []];
            }

            protected function containerSnapshot(): ?array
            {
                return array_shift($this->snapshots);
            }

            protected function settle(): void
            {
                $this->settled++;
            }
        };
    }
}
