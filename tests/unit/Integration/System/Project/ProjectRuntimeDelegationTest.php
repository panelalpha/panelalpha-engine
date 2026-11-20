<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Networking;
use App\System\Project\PhpHosting;
use App\System\Project\Runtime;
use App\System\Project\Snapshots;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** What the project does with each kind of runtime it runs on, and the permission hand-over of a home. */
class ProjectRuntimeDelegationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-runtime-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_php_hosting_operations_reach_the_php_runtime(): void
    {
        $runtime = Mockery::mock(PhpHosting::class);
        $runtime->shouldReceive('syncServices')->once();
        $runtime->shouldReceive('reloadApache')->once();
        $runtime->shouldReceive('enableApacheMod')->once()->with('rewrite');
        $runtime->shouldReceive('disableApacheMod')->once()->with('autoindex');
        $runtime->shouldReceive('rebuildDomains')->once();
        $runtime->shouldReceive('deleteAllDomainsConfigs')->once();
        $runtime->shouldReceive('fpmApache')->twice()->andReturnNull();
        $project = $this->projectOn($runtime);

        $project->syncServices();
        $project->reloadApache();
        $project->enableApacheMod('rewrite');
        $project->disableApacheMod('autoindex');
        $project->rebuildDomains();
        $project->deleteAllDomainsConfigs();
        $project->deleteDomainsConfigs(['shop.example.test']);

        $this->assertSame([], $project->listDomains());
    }

    public function test_php_hosting_operations_are_skipped_on_a_container_runtime(): void
    {
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldNotReceive('syncServices', 'reloadApache', 'enableApacheMod', 'disableApacheMod');
        $project = $this->projectOn($runtime);

        $project->syncPhpHandlersScripts();
        $project->syncServices();
        $project->reloadApache();
        $project->enableApacheMod('rewrite');
        $project->disableApacheMod('autoindex');
        $project->rebuildDomains();
        $project->deleteAllDomainsConfigs();
        $project->deleteDomainsConfigs(['shop.example.test']);

        $this->assertSame([], $project->listDomains());
    }

    public function test_container_operations_reach_the_dind_runtime(): void
    {
        $health = Mockery::mock(AppHealth::class);
        $health->shouldReceive('startFailure')->once()->andReturn('exited with code 1');
        $networking = Mockery::mock(Networking::class);
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldReceive('start')->once()->with(true)->andReturn(['stdout' => 'up', 'stderr' => '', 'exit_code' => 0]);
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('importProjectArchive')->once()->with('/tmp/app.zip');
        $runtime->shouldReceive('preCheckFromSources')->once();
        $runtime->shouldReceive('prepareFromSources')->once();
        $runtime->shouldReceive('app')->andReturn($app);
        $runtime->shouldReceive('appHealth')->andReturn($health);
        $runtime->shouldReceive('networking')->andReturn($networking);
        $project = $this->projectOn($runtime);
        $networking->shouldReceive('detectAndCreateProxyRules')->once()->with($project->model());

        $project->importProjectArchive('/tmp/app.zip');
        $project->preCheckUserApp();
        $project->prepareUserAppFromSources();
        $project->syncGeneratedProxyRules();

        $this->assertSame(['stdout' => 'up', 'stderr' => '', 'exit_code' => 0], $project->startUserApp(true));
        $this->assertSame('exited with code 1', $project->startedAppFailure());
    }

    public function test_a_container_runtime_without_an_app_cannot_start_one(): void
    {
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('app')->andReturnNull();
        $runtime->shouldReceive('kind')->andReturn('dind');
        $project = $this->projectOn($runtime);

        $this->assertNull($project->copyVolumesForClone());
        $this->assertSame(0, $project->copyVolumeDataFrom($project));
        $this->expectExceptionMessage('Cannot start application before deploy strategy is set.');
        $project->startUserApp();
    }

    public function test_container_only_operations_are_refused_or_skipped_on_php_hosting(): void
    {
        $runtime = Mockery::mock(PhpHosting::class);
        $runtime->shouldReceive('kind')->andReturn('php-hosting');
        $project = $this->projectOn($runtime);

        $project->prepareUserAppFromSources();
        $project->syncGeneratedProxyRules();
        $project->adoptCopiedEnvVars(new User());
        $this->assertNull($project->startedAppFailure());
        $this->assertNull($project->copyVolumesForClone());

        foreach ([
            'startUserApp is not available for this project type.' => fn () => $project->startUserApp(),
            'This operation requires a DinD project.' => fn () => $project->importProjectArchive('/tmp/app.zip'),
        ] as $message => $call) {
            try {
                $call();
                $this->fail("Expected: {$message}");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_a_runtime_that_only_says_it_is_dind_has_no_volumes_to_copy(): void
    {
        $runtime = Mockery::mock(Runtime::class);
        $runtime->shouldReceive('kind')->andReturn('dind');

        $this->assertNull($this->projectOn($runtime)->copyVolumesForClone());
    }

    public function test_wp_cli_is_refused_on_a_runtime_that_is_neither_kind(): void
    {
        $project = $this->projectOn(Mockery::mock(Runtime::class));

        $this->expectExceptionMessage('WP-CLI is not available for this project.');
        $project->runWpCli(['core', 'version']);
    }

    public function test_the_snapshots_belong_to_the_project(): void
    {
        $this->assertInstanceOf(Snapshots::class, $this->projectOn(Mockery::mock(Runtime::class))->snapshots());
    }

    public function test_an_existing_runtime_is_deprovisioned_by_the_teardown(): void
    {
        $runtime = Mockery::mock(Runtime::class);
        $runtime->shouldReceive('exists')->once()->andReturnTrue();
        $runtime->shouldReceive('remove')->once();
        $system = $this->recordingSystem(['users.uid' => 1]);

        $this->projectOn($runtime, $system)->tearDownHosting();

        $this->assertContains('users.uid', $system->actions());
    }

    public function test_a_teardown_whose_checks_fail_still_removes_the_container_runtime_and_logs_both(): void
    {
        Log::spy();
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('exists')->andThrow(new \Exception('compose file unreadable'));
        $runtime->shouldReceive('remove')->once()->andThrow(new \Exception('inner daemon gone'));
        $system = $this->recordingSystem(['users.uid' => 1]);

        $this->projectOn($runtime, $system)->tearDownHosting();

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === "Could not deprovision project for user 'shop': compose file unreadable"
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === "Could not remove DinD runtime for user 'shop': inner daemon gone"
        )->once();
        $this->assertContains('users.uid', $system->actions());
    }

    public function test_a_provision_whose_cleanup_also_fails_reports_the_first_failure(): void
    {
        Log::spy();
        $runtime = Mockery::mock(Runtime::class);
        $runtime->shouldReceive('exists')->andReturnFalse();
        $runtime->shouldReceive('remove')->once()->andThrow(new \RuntimeException('remove failed'));
        $system = new class () extends System {
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('mkdir failed');
            }
        };

        try {
            $this->projectOn($runtime, $system)->provision();
            $this->fail('Expected the provision to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('mkdir failed', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === "Cleanup after failed provision for 'shop' also failed: remove failed"
        )->once();
    }

    public function test_a_staged_home_is_handed_over_with_its_root_owned_top_level_secured(): void
    {
        $staged = $this->tmpRoot . '/staged';
        mkdir($staged, 0777);
        chmod($staged, 0777);
        if (posix_geteuid() === 0) {
            chown($staged, 1001);
        }
        $system = $this->recordingSystem(['files.isSymlink' => 1, 'files.isDirectory' => 0]);

        $this->projectOn(null, $system, ['UID' => 1001, 'GID' => 1001, 'template' => 'dind'])->handStagedHomeToAccount($staged . '/');

        $this->assertSame([
            ['chown', '--', 'root:root', $staged],
            ['chmod', '--', '755', $staged],
        ], array_slice($system->plainCommands(), 0, 2));
        $this->assertContains(['chown', '--', 'root:root', $staged . '/docker'], $system->plainCommands());
        $this->assertContains(['chmod', '--', '700', $staged . '/.panelalpha'], $system->plainCommands());
    }

    public function test_a_staged_home_that_does_not_exist_is_refused(): void
    {
        $missing = $this->tmpRoot . '/missing';

        $this->expectExceptionMessage("Directory does not exist: {$missing}");
        $this->projectOn(null, $this->recordingSystem([]), ['UID' => 1001, 'GID' => 1001])->handStagedHomeToAccount($missing);
    }

    public function test_an_account_without_ids_is_not_handed_anything(): void
    {
        Log::spy();
        $system = $this->recordingSystem([]);

        $this->projectOn(null, $system)->handStagedHomeToAccount($this->tmpRoot);

        $this->assertSame([], $system->actions());
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'Cannot fix file permissions for user shop, UID/GID is missing'
        )->once();
    }

    public function test_a_domains_litespeed_cache_goes_to_the_web_server_user(): void
    {
        $home = $this->tmpRoot . '/home';
        mkdir($home);
        $system = $this->recordingSystem(['files.isSymlink' => 1, 'files.isDirectory' => 0], $home);
        $model = new class () extends User {
            public function getDomains(): array
            {
                $domain = new DomainModel();
                $domain->domain = 'shop.example.test';

                return [$domain];
            }
        };
        $model->username = 'shop';
        $model->setDetails(['UID' => 1001, 'GID' => 1002, 'template' => 'default']);

        (new Project($system, $model))->fixPermissions();

        $this->assertContains(
            ['chown', '-R', '--', 'nobody:1001', $home . '/shop.example.test/.lscache'],
            $system->plainCommands()
        );
    }

    /** @param array<string, mixed> $details */
    private function projectOn(?Runtime $runtime, ?System $system = null, array $details = []): Project
    {
        $model = new User();
        $model->username = 'shop';
        $model->setDetails(['template' => 'default', ...$details]);
        $project = new Project($system ?? new System(), $model);
        if ($runtime !== null) {
            (new \ReflectionProperty(Project::class, 'runtime'))->setValue($project, $runtime);
        }

        return $project;
    }

    /**
     * A host that records each broker request and answers it with the exit code given for its action, 0 otherwise.
     *
     * @param array<string, int> $exitCodes
     */
    private function recordingSystem(array $exitCodes, ?string $home = null): System
    {
        return new class ($exitCodes, $home ?? $this->tmpRoot . '/nohome') extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, int> $exitCodes */
            public function __construct(private array $exitCodes, private string $home)
            {
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->home;
            }

            public function projectDirPath(string $username): string
            {
                return $this->home . '-engine';
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;

                return FakeProcess::of($this->exitCodes[LocalRoot::action($cmd) ?? ''] ?? 0);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;

                return '';
            }

            /** @return list<string> */
            public function actions(): array
            {
                return array_values(array_filter(array_map(static fn (array $cmd): ?string => LocalRoot::action($cmd), $this->commands)));
            }

            /** @return list<list<string>> */
            public function plainCommands(): array
            {
                return array_map(static fn (array $cmd): array => LocalRoot::plain($cmd), $this->commands);
            }
        };
    }
}
