<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\SilentPortCheck;
use Tests\TestCase;

/**
 * A container that is up and listens on nothing yet when the
 * deploy's ~105 s probe gives up is still installing itself (Magento's
 * setup:install at start). The deploy keeps reading its sockets instead of
 * calling it partial.
 */
class SlowStartWaitTest extends TestCase
{
    public const RUNNING_SNAPSHOT = '{"name":"/project-app-1","service":"app","state":"running","exit":0,"restarts":0}';

    /** @param list<?array<string, mixed>> $silentChecks one per poll */
    private function health(array $details, array $silentChecks, array $snapshots = []): AppHealth
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn($details);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);

        return new class ($dind, $silentChecks, $snapshots) extends AppHealth {
            public int $now = 1000;
            public array $slept = [];
            public array $observed = [];

            public function __construct(Dind $dind, private array $silentChecks, private array $snapshots)
            {
                parent::__construct($dind);
            }

            public function wait(array $report, DeployLogger $logger): array
            {
                return $this->awaitSlowStart($report, SlowStartWaitTest::RUNNING_SNAPSHOT, $logger);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false): ?array
            {
                $this->observed[] = $attempts;

                return ['healthy' => true, 'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]], 'checks' => []];
            }

            protected function silentCheck(array $ports): ?array
            {
                return $this->silentChecks === [] ? self::stillStarting() : array_shift($this->silentChecks);
            }

            protected function containerSnapshot(): ?string
            {
                return $this->snapshots === [] ? SlowStartWaitTest::RUNNING_SNAPSHOT : array_shift($this->snapshots);
            }

            protected function clock(): int
            {
                return $this->now;
            }

            protected function pause(int $seconds): void
            {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            }

            public static function stillStarting(): array
            {
                return ['id' => SilentPortCheck::ID, 'evidence' => ['silent' => ['app is running but listens on no TCP port yet'], 'starting' => ['app']]];
            }
        };
    }

    private ?DeployLogger $log = null;

    private function logger(): DeployLogger
    {
        $username = 'slowstart' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->log = DeployLogger::start($username);

        return $this->log;
    }

    /** @return list<string> */
    private function lines(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $e): string => $e['msg'], $this->log?->entries() ?? []),
            static fn (string $l): bool => str_starts_with($l, 'Still starting')
        ));
    }

    private static function silentReport(): array
    {
        return [
            'healthy' => false,
            'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]],
            'checks' => [[
                'id' => SilentPortCheck::ID,
                'status' => 'fail',
                'severity' => 'error',
                'evidence' => ['silent' => ['app is running but listens on no TCP port yet'], 'starting' => ['app']],
            ]],
        ];
    }

    public function test_it_waits_until_a_socket_appears_then_probes_with_the_full_budget(): void
    {
        $bound = ['id' => SilentPortCheck::ID, 'evidence' => ['silent' => ['app listens on 8080, but did not answer HTTP there'], 'starting' => []]];
        $health = $this->health([], [$this->stillStartingCheck(), $this->stillStartingCheck(), $bound]);

        $report = $health->wait(self::silentReport(), $this->logger());

        $this->assertSame([15, 15, 15], $health->slept);
        $this->assertSame([15], $health->observed, 'the deploy probe again, all 15 attempts');
        $this->assertTrue($report['healthy']);
        $this->assertSame(['Still starting: app listens on nothing yet; waiting up to 5 minutes more'], $this->lines());
    }

    public function test_it_gives_up_after_five_minutes_by_default(): void
    {
        $health = $this->health([], []);

        $health->wait(self::silentReport(), $this->logger());

        $this->assertSame(300, array_sum($health->slept));
        $this->assertSame([1], $health->observed, 'one last look for the verdict');
    }

    public function test_a_manifest_start_period_replaces_the_ceiling(): void
    {
        $health = $this->health([AppHealth::DETAIL_START_PERIOD => 900], []);

        $health->wait(self::silentReport(), $this->logger());

        $this->assertSame(900, array_sum($health->slept));
        $this->assertSame(['Still starting: app listens on nothing yet; waiting up to 15 minutes more'], $this->lines());
    }

    public function test_start_period_zero_turns_the_wait_off(): void
    {
        $health = $this->health([AppHealth::DETAIL_START_PERIOD => 0], []);

        $report = $health->wait(self::silentReport(), $this->logger());

        $this->assertSame([], $health->slept);
        $this->assertSame([], $health->observed);
        $this->assertFalse($report['healthy']);
    }

    public function test_a_container_that_restarts_ends_the_wait_at_once(): void
    {
        $restarted = '{"name":"/project-app-1","service":"app","state":"running","exit":0,"restarts":1}';
        $health = $this->health([], [], [$restarted]);

        $health->wait(self::silentReport(), $this->logger());

        $this->assertSame([15], $health->slept);
        $this->assertSame([1], $health->observed);
    }

    public function test_a_container_that_stopped_running_ends_the_wait_at_once(): void
    {
        // SilentPortCheck only reads running containers: an exited one gives no verdict.
        $health = $this->health([], [null]);

        $health->wait(self::silentReport(), $this->logger());

        $this->assertSame([15], $health->slept);
        $this->assertSame([1], $health->observed);
    }

    public function test_an_app_listening_on_the_wrong_port_is_not_waited_for(): void
    {
        $report = self::silentReport();
        $report['checks'][0]['evidence'] = ['silent' => ['app listens on 3000, not on 8080.'], 'starting' => []];
        $health = $this->health([], []);

        $this->assertSame($report, $health->wait($report, $this->logger()));
        $this->assertSame([], $health->slept);
    }

    public function test_a_crash_loop_is_not_waited_for(): void
    {
        $report = self::silentReport();
        $report['checks'] = [['id' => AppHealth::CHECK_RESTART_LOOPING, 'status' => 'fail', 'severity' => 'error']];
        $health = $this->health([], []);

        $this->assertSame($report, $health->wait($report, $this->logger()));
        $this->assertSame([], $health->slept);
    }

    /**
     * report() hands back the report taken after the wait, not the first
     * probe's: AppLauncher reroutes the site from the per-port answers it
     * returns, and those must be the ones the slow app gave once it bound.
     */
    public function test_report_returns_what_the_probe_saw_after_the_wait(): void
    {
        $username = 'slowstart' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->log = DeployLogger::start($username);
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('username')->willReturn($username);
        $dind->method('shell')->willReturnCallback(static fn () => new Dind\ShellOperations($dind));
        $bound = ['id' => SilentPortCheck::ID, 'evidence' => ['silent' => [], 'starting' => []]];

        $health = new class ($dind, $bound) extends AppHealth {
            private int $now = 1000;
            private int $observations = 0;

            public function __construct(Dind $dind, private array $bound)
            {
                parent::__construct($dind);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false): ?array
            {
                if ($this->observations++ === 0) {
                    $report = (fn () => self::silentReport())->call(new SlowStartWaitTest('x'));
                    $report['ports'][0] += ['scheme' => 'http', 'time' => null, 'detail' => 'connection refused'];

                    return $report;
                }

                return ['healthy' => true, 'ports' => [['port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK, 'http_code' => 200, 'time' => 0.1, 'detail' => 'HTTP 200']], 'checks' => []];
            }

            protected function silentCheck(array $ports): ?array
            {
                return $this->bound;
            }

            protected function containerSnapshot(): ?string
            {
                return SlowStartWaitTest::RUNNING_SNAPSHOT;
            }

            protected function clock(): int
            {
                return $this->now;
            }

            protected function pause(int $seconds): void
            {
                $this->now += $seconds;
            }
        };

        $report = $health->report();

        $this->assertNotNull($report);
        $this->assertSame(200, $report['ports'][0]['http_code']);
    }

    private function stillStartingCheck(): array
    {
        return ['id' => SilentPortCheck::ID, 'evidence' => ['silent' => ['app is running but listens on no TCP port yet'], 'starting' => ['app']]];
    }
}
