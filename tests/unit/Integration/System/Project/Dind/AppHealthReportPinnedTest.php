<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use ReflectionMethod;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * What report() returns and writes into the deploy log for each kind of observation, and
 * what the health checks fall back to when a project's own manifest cannot be read.
 */
class AppHealthReportPinnedTest extends TestCase
{
    private const SNAPSHOT = [[
        'Name' => '/project-app-1',
        'Config' => ['Labels' => ['com.docker.compose.service' => 'app']],
        'State' => ['Status' => 'running', 'ExitCode' => 0],
        'RestartCount' => 0,
    ]];

    private ?DeployLogger $log = null;

    private string $username = '';

    public function test_no_deploy_log_reports_nothing(): void
    {
        $health = $this->health([['healthy' => true, 'ports' => []]], logged: false);

        $this->assertNull($health->report());
        $this->assertSame([], $health->observed);
    }

    public function test_a_probe_that_could_not_run_reports_nothing(): void
    {
        $health = $this->health([null]);

        $this->assertNull($health->report());
        $this->assertSame([[3, 15, 4, true]], $health->observed);
        $this->assertSame([], $this->lines());
    }

    public function test_an_error_is_warned(): void
    {
        $health = $this->health([['healthy' => false, 'ports' => [], 'error' => 'could not run the probe: boom']]);

        $this->assertNull($health->report());
        $this->assertSame([['warn', 'Health check: could not run the probe: boom']], $this->lines());
    }

    public function test_no_port_still_reports_a_late_restart_loop(): void
    {
        $health = $this->health(
            [['healthy' => null, 'ports' => []]],
            [self::SNAPSHOT, [['Name' => '/project-app-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'app']], 'State' => ['Status' => 'restarting', 'ExitCode' => 1], 'RestartCount' => 2]]]
        );

        $this->assertNull($health->report());
        $this->assertSame(1, $health->settled);
        $this->assertSame([
            ['dim', 'Health check: the application publishes no port to probe'],
            ['warn', 'Check failed: The application is restarting, not running. Docker reports app (restarting, last exit 1, restarted 2 times). It may answer between restarts, but it does not stay up. Read the container output; the reason it exits is there. A process that exits 0 is restarted too, e.g. a command ending in a shell with no TTY.'],
        ], $this->lines());
    }

    public function test_every_port_reachability_and_check_is_logged(): void
    {
        $report = [
            'healthy' => false,
            'serving' => 'error_page',
            'ports' => [
                ['port' => 8000, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK, 'http_code' => 200, 'time' => 0.01, 'detail' => 'HTTP 200'],
                ['port' => 8001, 'scheme' => null, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null, 'time' => null, 'detail' => 'connection refused'],
                ['port' => 8002, 'scheme' => 'https', 'status' => AppHealth::STATUS_FAIL, 'http_code' => 502, 'time' => 1.0, 'detail' => 'HTTP 502'],
            ],
            'domain' => ['verdict' => AppHealth::REACH_DIFFERS, 'domain' => 'shop.example.com', 'http_code' => 404, 'detail' => 'answered something else'],
            'checks' => [
                ['id' => 'a', 'status' => 'fail', 'severity' => 'warning', 'title' => 'Warned.', 'detail' => 'Why.', 'fix' => ''],
                ['id' => 'b', 'status' => 'fail', 'severity' => 'info', 'title' => 'Noted.'],
                ['id' => 'c', 'status' => 'pass', 'severity' => 'error', 'title' => 'Passed.'],
                'not a check',
            ],
        ];
        $health = $this->health([$report], [self::SNAPSHOT, self::SNAPSHOT]);

        $this->assertSame($report, $health->report());
        $this->assertSame(1, $health->settled);
        $this->assertSame([
            ['ok', 'Health check: http://' . TestNetwork::LOOPBACK . ':8000/ answered HTTP 200 (0.01s)'],
            ['warn', 'Health check: http://' . TestNetwork::LOOPBACK . ':8001/ did not answer — connection refused'],
            ['warn', 'Health check: https://' . TestNetwork::LOOPBACK . ':8002/ answered HTTP 502 — the application is failing'],
            ['warn', AppHealth::describeReach($report['domain'])],
            ['warn', 'Check: Warned. Why.'],
            ['dim', 'Check: Noted.'],
        ], $this->lines());
    }

    public function test_the_placeholder_answering_is_warned_not_passed(): void
    {
        $report = [
            'healthy' => false,
            'serving' => 'placeholder',
            'ports' => [
                ['port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK, 'http_code' => 404, 'time' => 0.001, 'detail' => 'HTTP 404'],
            ],
            'checks' => [],
        ];
        $health = $this->health([$report], [self::SNAPSHOT, self::SNAPSHOT]);

        $health->report();

        $this->assertSame([
            ['warn', 'Health check: http://' . TestNetwork::LOOPBACK . ':8080/ answered HTTP 404 (0.001s) with the PanelAlpha placeholder page: no application is running'],
        ], $this->lines());
    }

    public function test_an_unreadable_repository_manifest_gives_no_manifest(): void
    {
        $home = sys_get_temp_dir() . '/pa-pinned-manifest-' . bin2hex(random_bytes(6));
        mkdir($home . '/project/.panelalpha', 0o777, true);
        file_put_contents($home . '/project/.panelalpha/panelalpha.yaml', "id: [unclosed\n");
        try {
            $declared = (new ReflectionMethod(AppHealth::class, 'declaredManifest'))
                ->invoke(null, ['deploy_platform' => 'acme-unknown', 'home_dir' => $home]);
        } finally {
            exec('rm -rf ' . escapeshellarg($home));
        }

        $this->assertNull($declared);
    }

    /**
     * @param  list<?array<string, mixed>>  $observations
     * @param  list<list<array<string, mixed>>|null>  $snapshots
     */
    private function health(array $observations, array $snapshots = [], bool $logged = true): AppHealth
    {
        $this->username = 'pinned' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        if ($logged) {
            $this->log = DeployLogger::start($username);
        }

        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);
        $user->method('getAppPort')->willReturn(null);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('username')->willReturn($username);
        $dind->method('shell')->willReturn(new ShellOperations($dind));

        return new class ($dind, $observations, $snapshots) extends AppHealth {
            /** @var list<array{0: int, 1: int, 2: int, 3: bool}> */
            public array $observed = [];

            public int $settled = 0;

            public function __construct(Dind $dind, private array $observations, private array $snapshots)
            {
                parent::__construct($dind);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): ?array
            {
                $this->observed[] = [$timeout, $attempts, $delay, $waitOnServerError];

                return array_shift($this->observations);
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

    /** @return list<array{0: string, 1: string}> */
    private function lines(): array
    {
        return array_map(
            static fn (array $entry): array => [(string) ($entry['level'] ?? ''), (string) $entry['msg']],
            $this->log?->entries() ?? []
        );
    }
}
