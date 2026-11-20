<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\ComposePsFixture;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * What the health probe does when one of its own steps cannot run: the extra paths a recipe asks
 * about, a daemon or a model that throws, a verdict that cannot be saved, and the deploy log's reach lines.
 */
class AppHealthEdgesTest extends TestCase
{
    private const STOPPED_BY_SIGNAL = '{"Name":"project-app-1","Service":"app","State":"exited","Status":"Exited (137) 1 minute ago","ExitCode":137}';

    /** @var list<string> what the account was asked to run */
    private array $ran = [];

    /** `compose ps --all` of the stack. */
    private string $ps = '';

    private bool $daemonFails = false;

    /** The run file; null when it cannot be read. */
    private ?string $runFile = null;

    /** What the port probe answers. */
    private ?string $probe = null;

    /** What the path probe answers. */
    private string $paths = '';

    private ?DeployLogger $log = null;

    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        SourceRecipes::flush();
        $this->username = 'edges' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        CheckRegistry::flush();
        SourceRecipes::flush();
        $this->log = null;
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_recipe_check_on_another_path_is_asked_of_the_port_that_answered(): void
    {
        $this->paths = "/api/health\t500 0.004\t" . base64_encode('{"status":"error","database":"unreachable"}') . "\n";
        $user = $this->user([
            AppHealth::DETAIL_RUNTIME => 'compose',
            AppHealth::DETAIL_PLATFORM => 'market-radar',
            AppHealth::DETAIL_CHECKS_DIR => SourceRecipes::checksDirectory(SourceRecipes::directoryFor('https://github.com/vvolv/market-radar') ?? ''),
        ]);
        $page = base64_encode('<!doctype html><html><head><title>Market Radar</title></head><body><div id="root"></div></body></html>');

        $report = $this->probing($user, "8080\thttp\t200 0.01\t\t{$page}\t/\n")->check();

        $this->assertSame('database_error', $report['serving']);
        $asked = array_values(array_filter($this->ran, static fn (string $line): bool => str_contains($line, 'for path in')));
        $this->assertCount(1, $asked);
        $this->assertStringContainsString("for path in '/api/health'; do", $asked[0]);
        $this->assertStringContainsString("'http://".TestNetwork::LOOPBACK.":8080'", $asked[0]);
    }

    public function test_checks_that_cannot_run_leave_the_serving_verdict_unknown(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willThrowException(new \RuntimeException('details unreadable'));

        $report = $this->probing($user, "8080\thttp\t200 0.01\t\t\t/\n")->check();

        $this->assertSame(CheckRunner::SERVING_UNKNOWN, $report['serving']);
        $this->assertTrue($report['healthy']);
    }

    public function test_a_stack_that_cannot_be_listed_adds_no_restart_check(): void
    {
        $this->daemonFails = true;

        $report = $this->probing($this->user([]), "8080\thttp\t000 0\tconnection refused\n")->check();

        $this->assertFalse($report['healthy']);
        $this->assertNotContains(AppHealth::CHECK_RESTART_LOOPING, array_column($report['checks'], 'id'));
        $this->assertNotContains(AppHealth::CHECK_APP_STOPPED, array_column($report['checks'], 'id'));
    }

    public function test_an_app_stopped_by_a_signal_is_read_with_its_restart_counts(): void
    {
        $this->ps = self::STOPPED_BY_SIGNAL;
        $this->runFile = "services:\n  app:\n    image: acme/app\n    ports: ['8080:8080']\n    restart: unless-stopped\n";

        $report = $this->probing($this->user([]), "8080\thttp\t000 0\tconnection refused\n")->check();

        $this->assertSame(CheckRunner::SERVING_STOPPED, $report['serving']);
        $this->assertContains(AppHealth::CHECK_APP_STOPPED, array_column($report['checks'], 'id'));
        $this->assertNotEmpty(array_filter($this->ran, static fn (string $line): bool => str_contains($line, 'account-docker.containers')));
    }

    public function test_no_probe_result_has_no_restart_check_and_an_unreachable_daemon_no_snapshot(): void
    {
        $this->daemonFails = true;
        $health = $this->probing($this->user([]), '');

        $this->assertNull((new \ReflectionMethod(AppHealth::class, 'restartLoopCheck'))->invoke($health, []));
        $this->assertNull((new \ReflectionMethod(AppHealth::class, 'containerSnapshot'))->invoke($health));
        $this->assertCount(1, $this->ran, 'the snapshot asked the daemon once');
    }

    public function test_the_defaults_the_slow_start_wait_runs_on(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willThrowException(new \RuntimeException('details unreadable'));
        $health = $this->probing($user, '');

        $this->assertSame(300, (new \ReflectionMethod(AppHealth::class, 'startPeriod'))->invoke($health));
        $this->assertNull((new \ReflectionMethod(AppHealth::class, 'silentCheck'))->invoke($health, []));
        $this->assertEqualsWithDelta(time(), (new \ReflectionMethod(AppHealth::class, 'clock'))->invoke($health), 2);
        $started = microtime(true);
        (new \ReflectionMethod(AppHealth::class, 'pause'))->invoke($health, 0);
        $this->assertLessThan(1, microtime(true) - $started);
        $duration = new \ReflectionMethod(AppHealth::class, 'duration');
        $this->assertSame('45 s', $duration->invoke(null, 45));
        $this->assertSame('1 minute', $duration->invoke(null, 60));
        $this->assertSame('5 minutes', $duration->invoke(null, 300));
    }

    public function test_a_probe_that_throws_is_no_verdict_and_a_cancel_is_rethrown(): void
    {
        $health = $this->observing($this->user([]), new \RuntimeException('probe exploded'));

        $this->assertNull($health->observe());

        $cancelled = $this->observing($this->user([]), new DeployCancelledException('cancelled'));
        $this->expectException(DeployCancelledException::class);
        $cancelled->observe();
    }

    public function test_a_verdict_that_cannot_be_saved_is_still_returned(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);
        $user->method('save')->willThrowException(new \RuntimeException('database gone'));
        $report = ['healthy' => true, 'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]]];

        $this->assertSame($report, $this->observing($user, $report)->observe());
    }

    public function test_a_start_with_no_verdict_is_no_failure(): void
    {
        $this->assertNull($this->starting($this->user([]), null)->startFailure());
    }

    public function test_a_failed_start_whose_previous_verdict_cannot_be_put_back_still_says_why(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);
        $user->method('setDetails')->willThrowException(new \RuntimeException('details are read-only'));
        $looping = ['id' => AppHealth::CHECK_RESTART_LOOPING, 'title' => 'The application is restarting, not running.', 'detail' => 'Docker reports app (restarting).'];
        $report = ['healthy' => false, 'ports' => [['port' => 8080, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]], 'checks' => [$looping]];

        $this->assertSame(
            'The application is restarting, not running. Docker reports app (restarting).',
            $this->starting($user, $report)->startFailure()
        );
    }

    public function test_a_domain_that_answers_is_ok_and_one_skipped_is_dimmed(): void
    {
        $reached = ['verdict' => AppHealth::REACH_OK, 'domain' => 'shop.example.com', 'http_code' => 200, 'detail' => 'answered as the app does'];
        $skipped = ['verdict' => AppHealth::REACH_SKIPPED, 'domain' => null, 'http_code' => null, 'detail' => 'the project has no domain'];
        $this->log = DeployLogger::start($this->username);

        $answered = [['port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK, 'http_code' => 200, 'time' => 0.01, 'detail' => 'HTTP 200']];
        $this->reporting(['healthy' => true, 'ports' => $answered, 'domain' => $reached])->report();
        $this->reporting(['healthy' => true, 'ports' => $answered, 'domain' => $skipped])->report();

        $lines = array_map(static fn (array $entry): array => [$entry['level'], $entry['msg']], $this->log->entries());
        $this->assertContains(['ok', AppHealth::describeReach($reached)], $lines);
        $this->assertContains(['dim', AppHealth::describeReach($skipped)], $lines);
    }

    public function test_a_remembered_check_that_is_not_one_is_skipped(): void
    {
        $failed = AppHealth::failedChecks([AppHealth::DETAIL_CHECKS => [
            'not a check',
            ['id' => 7],
            ['id' => 'no-server-error', 'severity' => 'error', 'message' => 'HTTP 500'],
        ]]);

        $this->assertSame(['no-server-error'], array_column($failed, 'id'));
    }

    /** @param array<string, mixed> $details */
    private function user(array $details): User
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn($details);
        $user->method('getAppPort')->willReturn(8080);
        $user->method('getMainDomain')->willThrowException(new \RuntimeException('no database'));

        return $user;
    }

    private function project(User $user): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account((array) $cmd));
        $filesystem = $this->createStub(System\Filesystem::class);
        $filesystem->method('fileGetContents')->willReturnCallback(
            fn (): string => $this->runFile ?? throw new \RuntimeException('no run file here')
        );
        $system->method('filesystem')->willReturn($filesystem);

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('username')->willReturn($this->username);
        $dind->method('system')->willReturn($system);
        $dind->method('homeDirPath')->willReturn('/home/acme');
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('userAppComposeProjectName')->willReturn('project');
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));

        return $dind;
    }

    /** check() of port 8080 answering $probe, with the domain left out. */
    private function probing(User $user, string $probe): AppHealth
    {
        $this->probe = $probe;

        return new class ($this->project($user)) extends AppHealth {
            public function ports(): array
            {
                return [8080];
            }

            public function reachability(array $ports, int $timeout = 5): array
            {
                return ['verdict' => AppHealth::REACH_SKIPPED, 'domain' => null, 'http_code' => null, 'detail' => 'stubbed'];
            }
        };
    }

    /** observe() over a check() that returns or throws $outcome. */
    private function observing(User $user, array|\Throwable $outcome): AppHealth
    {
        return new class ($this->project($user), $outcome) extends AppHealth {
            public function __construct(Dind $dind, private array|\Throwable $outcome)
            {
                parent::__construct($dind);
            }

            public function check(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): array
            {
                return $this->outcome instanceof \Throwable ? throw $this->outcome : $this->outcome;
            }
        };
    }

    /** startFailure() over one observation and a stack that never changes. */
    private function starting(User $user, ?array $report): AppHealth
    {
        return new class ($this->project($user), $report) extends AppHealth {
            public function __construct(Dind $dind, private ?array $report)
            {
                parent::__construct($dind);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): ?array
            {
                return $this->report;
            }

            protected function containerSnapshot(): ?array
            {
                return null;
            }

            protected function awaitSlowStart(array $report, ?array $before, ?DeployLogger $logger): array
            {
                return $report;
            }

            protected function settle(): void
            {
            }
        };
    }

    /** report() of one observation under this test's deploy log. */
    private function reporting(array $report): AppHealth
    {
        return new class ($this->project($this->user([])), $report) extends AppHealth {
            public function __construct(Dind $dind, private array $report)
            {
                parent::__construct($dind);
            }

            public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): ?array
            {
                return $this->report;
            }

            protected function containerSnapshot(): ?array
            {
                return null;
            }

            protected function awaitSlowStart(array $report, ?array $before, ?DeployLogger $logger): array
            {
                return $report;
            }

            protected function settle(): void
            {
            }
        };
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = implode(' ', LocalRoot::plain($argv));
        $this->ran[] = $line;
        if ($this->daemonFails && str_starts_with((string) LocalRoot::action($argv), 'account-docker.')) {
            throw new \RuntimeException('docker daemon not reachable');
        }
        if (str_contains($line, 'for path in')) {
            return $this->paths;
        }
        if (str_contains($line, 'for port in')) {
            return $this->probe ?? '';
        }

        return match (LocalRoot::action($argv)) {
            'account-docker.listContainers' => ComposePsFixture::listing($this->ps),
            'account-docker.containers' => ComposePsFixture::inspect($this->ps, array_slice(LocalRoot::values($argv), 1)),
            default => '',
        };
    }
}
