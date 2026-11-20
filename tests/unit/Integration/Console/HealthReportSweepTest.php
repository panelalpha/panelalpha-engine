<?php

namespace Tests\Integration\Console;

use App\Console\Commands\Deploy\HealthReportCommand;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * What the six-hourly sweep counts as not serving, and what it
 * leaves alone.
 */
class HealthReportSweepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach (['2014_10_12_000000_create_users_table.php', '2025_09_29_121922_add_status_to_users_table.php'] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_silence_with_no_failed_check_is_not_serving(): void
    {
        $silent = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]],
            AppHealth::DETAIL_CHECKS => [],
        ];

        $this->assertTrue(HealthReportCommand::notServing($silent));
        $this->assertTrue(HealthReportCommand::notServing([AppHealth::DETAIL_CHECKS => [['id' => 'app-stopped', 'severity' => 'error']]]));
        $this->assertFalse(HealthReportCommand::notServing([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => true,
            AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]],
        ]));
        // A worker with nothing to probe is not silence.
        $this->assertFalse(HealthReportCommand::notServing([AppHealth::DETAIL_CHECKED => false, AppHealth::DETAIL_HEALTHY => null]));
    }

    /**
     * The probe of an account whose container is down asks nothing and finds no failed check, yet the account serves
     * nothing: it is listed, served as stopped, with the probe's reason as the cause.
     */
    public function test_an_account_whose_container_is_down_is_listed_with_that_cause(): void
    {
        $user = User::create(['username' => 'downapp', 'domain' => 'downapp.example.test', 'details' => ['template' => 'dind', 'app_port' => 8000]]);

        $report = $this->healthOf($user)->observe(1, 1, 0);
        $details = $user->fresh()->getDetails();

        $this->assertTrue(HealthReportCommand::notServing($details));
        $this->assertSame(
            [
                'project' => 'downapp',
                'serving' => 'stopped',
                'checks' => 'the project\'s container is not running, so nothing was probed (service "dind" is not running)',
            ],
            HealthReportCommand::row('downapp', $report, $details),
        );
    }

    /** A probe that failed for any other reason is no verdict, and is not listed. */
    public function test_a_probe_that_could_not_run_is_not_listed(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_SERVING => null,
            AppHealth::DETAIL_PORTS => [],
            AppHealth::DETAIL_CHECKS => [],
        ];

        $this->assertNull(HealthReportCommand::row('oddapp', ['healthy' => false, 'ports' => [], 'error' => 'could not run the probe: boom'], $details));
    }

    /** Stopped on request, its container being down is what was asked for: the sweep leaves it out. */
    public function test_an_account_stopped_on_request_is_not_listed_though_its_container_is_down(): void
    {
        $user = User::create(['username' => 'parked', 'domain' => 'parked.example.test', 'details' => [
            'template' => 'dind',
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_SERVING => 'stopped',
            AppHealth::DETAIL_PORTS => [],
            AppHealth::DETAIL_CHECKS => [],
        ]]);
        $user->markAppStoppedByRequest(true);

        Artisan::call('project:health:report', ['project' => 'parked', '--local' => true, '--json' => true]);

        $sweep = json_decode(Artisan::output(), true);
        $this->assertSame(['swept' => 0, 'degraded' => 0, 'reported' => 0, 'projects' => []], $sweep);
    }

    /** The real probe on an account whose every docker command answers that its container is not running. */
    private function healthOf(User $user): AppHealth
    {
        $host = new class () extends System {
            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::failed("service \"dind\" is not running\n");
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return FakeProcess::failed("service \"dind\" is not running\n");
            }
        };
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('username')->andReturn($user->username);
        $dind->shouldReceive('composeFilePath')->andReturn('/opt/panelalpha/shared-hosting/users/' . $user->username . '/docker-compose.yml');
        $dind->shouldReceive('homeDirPath')->andReturn('/home/' . $user->username);
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('system')->andReturn($host);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));

        return new class ($dind) extends AppHealth {
            public function ports(): array
            {
                return [8000];
            }
        };
    }

    public function test_a_project_stopped_on_request_is_skipped(): void
    {
        $user = User::create([
            'username' => 'stoppedapp',
            'domain' => 'stoppedapp.example.test',
            'details' => ['template' => 'dind'],
        ]);
        $user->markAppStoppedByRequest(true);
        $this->assertTrue($user->fresh()->isAppStoppedByRequest());

        Artisan::call('project:health:report', ['project' => 'stoppedapp', '--local' => true]);

        $this->assertStringContainsString('Swept 0 project(s)', Artisan::output());
    }

    public function test_starting_the_app_again_clears_the_flag(): void
    {
        $user = User::create(['username' => 'restarted', 'domain' => 'restarted.example.test', 'details' => ['template' => 'dind']]);
        $user->markAppStoppedByRequest(true);
        $user->fresh()->markAppStoppedByRequest(false);

        $this->assertFalse($user->fresh()->isAppStoppedByRequest());
        $this->assertArrayNotHasKey('app_stopped_by_request', array_filter($user->fresh()->getDetails(), static fn ($v) => $v !== null));
    }

    /** A mistyped name is a failure, not an empty-but-healthy sweep. */
    public function test_an_unknown_project_exits_nonzero_without_a_json_payload(): void
    {
        $exit = Artisan::call('project:health:report', ['project' => 'nosuch999', '--local' => true, '--json' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No such project: 'nosuch999'", Artisan::output());
        $this->assertStringNotContainsString('"swept"', Artisan::output());
        $this->assertStringNotContainsString('Swept 0 project(s)', Artisan::output());
    }
}
