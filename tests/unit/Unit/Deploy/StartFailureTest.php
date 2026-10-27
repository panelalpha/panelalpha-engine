<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\SilentPortCheck;
use Tests\TestCase;

/**
 * A push starts the pushed app with no deploy log, and its start counts as
 * failed only when the deploy's own probe finds the app not running: nothing
 * answered, and it restarts, stopped, or never listened. A slow start is
 * waited for as a deploy waits for it.
 */
class StartFailureTest extends TestCase
{
    private const RUNNING = '{"name":"/project-app-1","service":"app","state":"running","exit":0,"restarts":0}';

    private const PREVIOUS = [
        AppHealth::DETAIL_CHECKED => true,
        AppHealth::DETAIL_HEALTHY => true,
        AppHealth::DETAIL_PORTS => [['port' => 8080, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]],
    ];

    /** @var list<array<string, mixed>> */
    private array $written = [];

    /**
     * @param  list<array<string, mixed>>  $reports  one per probe
     * @param  list<?array<string, mixed>>  $silentChecks  one per poll of a slow start
     */
    private function health(array $reports, array $silentChecks = []): AppHealth
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn(self::PREVIOUS + ['deploy_strategy' => 'dockerfile']);
        $user->method('setDetails')->willReturnCallback(function (array $details): array {
            $this->written[] = $details;

            return $details;
        });
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);

        return new class($dind, $reports, $silentChecks) extends AppHealth
        {
            public int $now = 1000;

            /** @var list<int> */
            public array $observed = [];

            public function __construct(Dind $dind, private array $reports, private array $silentChecks)
            {
                parent::__construct($dind);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): ?array
            {
                $this->observed[] = $attempts;

                return array_shift($this->reports);
            }

            protected function silentCheck(array $ports): ?array
            {
                return array_shift($this->silentChecks);
            }

            protected function containerSnapshot(): ?string
            {
                return StartFailureTest::runningSnapshot();
            }

            protected function settle(): void {}

            protected function clock(): int
            {
                return $this->now;
            }

            protected function pause(int $seconds): void
            {
                $this->now += $seconds;
            }
        };
    }

    public static function runningSnapshot(): string
    {
        return self::RUNNING;
    }

    /** @param  list<array<string, mixed>>  $checks */
    private static function silent(array $checks): array
    {
        return ['healthy' => false, 'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]], 'checks' => $checks];
    }

    private static function looping(): array
    {
        return [
            'id' => AppHealth::CHECK_RESTART_LOOPING,
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'The application is restarting, not running.',
            'detail' => 'Docker reports app (Restarting (1) 2 seconds ago).',
        ];
    }

    /** @param  list<string>  $starting */
    private static function silentPort(array $starting): array
    {
        return [
            'id' => SilentPortCheck::ID,
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'The application is running but does not answer on the port it publishes.',
            'detail' => 'app is running but listens on no TCP port yet.',
            'evidence' => ['silent' => ['app'], 'starting' => $starting],
        ];
    }

    public function test_a_crash_loop_with_nothing_answering_is_not_running(): void
    {
        $this->assertSame(
            'The application is restarting, not running. Docker reports app (Restarting (1) 2 seconds ago).',
            AppHealth::notRunning(self::silent([self::looping()]))
        );
        $stopped = ['id' => AppHealth::CHECK_APP_STOPPED, 'title' => 'The application container is stopped.'];
        $this->assertSame('The application container is stopped.', AppHealth::notRunning(self::silent([$stopped])));
        $this->assertNotNull(AppHealth::notRunning(self::silent([self::silentPort(['app'])])), 'up, and listens on nothing');
    }

    public function test_an_app_that_answers_or_publishes_no_port_is_left_to_the_deploy_warnings(): void
    {
        $erroring = ['healthy' => false, 'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_FAIL, 'http_code' => 502]], 'checks' => [self::looping()]];
        $worker = ['healthy' => null, 'ports' => [], 'checks' => [self::looping()]];

        $this->assertNull(AppHealth::notRunning($erroring), 'an answer, even an error');
        $this->assertNull(AppHealth::notRunning($worker), 'no port to answer on');
        $this->assertNull(AppHealth::notRunning(self::silent([self::silentPort([])])), 'listens, not on HTTP');
        $this->assertNull(AppHealth::notRunning(self::silent([])));
    }

    public function test_a_start_that_crash_loops_fails_and_keeps_the_verdict_from_before(): void
    {
        $health = $this->health([self::silent([self::looping()])]);

        $this->assertStringStartsWith('The application is restarting, not running.', (string) $health->startFailure());
        $this->assertSame([15], $health->observed, 'the deploy probe, all 15 attempts');
        $this->assertCount(1, $this->written);
        $this->assertTrue($this->written[0][AppHealth::DETAIL_HEALTHY]);
        $this->assertSame(self::PREVIOUS[AppHealth::DETAIL_PORTS], $this->written[0][AppHealth::DETAIL_PORTS]);
        $this->assertArrayHasKey(AppHealth::DETAIL_CHECKS, $this->written[0]);
        $this->assertNull($this->written[0][AppHealth::DETAIL_CHECKS]);
    }

    public function test_a_slow_start_is_waited_for_as_a_deploy_waits(): void
    {
        $answering = ['healthy' => true, 'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]], 'checks' => []];
        $health = $this->health(
            [self::silent([self::silentPort(['app'])]), $answering],
            [self::silentPort(['app']), self::silentPort([])]
        );

        $this->assertNull($health->startFailure());
        $this->assertSame([15, 15], $health->observed, 'probed again in full once it bound');
        $this->assertSame([], $this->written);
    }

    public function test_an_app_that_never_listens_fails_once_the_start_period_is_spent(): void
    {
        $stillStarting = self::silent([self::silentPort(['app'])]);
        $health = $this->health([$stillStarting, $stillStarting], array_fill(0, 30, self::silentPort(['app'])));

        $this->assertNotNull($health->startFailure());
        $this->assertSame(1300, $health->now, 'the full five minutes');
    }
}
