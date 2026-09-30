<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * engine#184/#295: every host build container may take the server's RAM less
 * the engine's share, so the builds run one at a time and the deploy log says
 * what the container got and why.
 */
class HostCompileBuildSlotTest extends TestCase
{
    private string $username = '';

    /** @var list<?string> */
    private array $memoryFlags = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'slot' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    /**
     * Run the real host PHP build and report, for each build container it
     * started, whether another process could take the build slot meanwhile.
     *
     * @return list<bool> one entry per build container: true when the slot was free
     */
    private function build(string $memory, int $times = 1, ?DindHostBuilder $builder = null): array
    {
        $system = new class extends System {
            /** @var list<bool> */
            public array $slotFreeDuringBuild = [];

            /** @var list<?string> the `--memory` each build container was started with */
            public array $memoryFlags = [];

            public function __construct()
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this) extends SystemFilesystem {
                    public function fileExists(string $path): bool
                    {
                        return str_ends_with($path, '/composer.json');
                    }

                    public function fileGetContents(string $path): string
                    {
                        return '{"require":{"php":"^8.3"}}';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->container((array) $cmd);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->container((array) $cmd);
            }

            /** A stand-in for the build container: probe the slot from outside it. */
            private function container(array $argv): Process
            {
                // Writing the runtime Composer manifest probes directories too.
                if (!in_array('docker', $argv, true)) {
                    $process = new Process(['php', '-r', 'exit(0);']);
                    $process->run();

                    return $process;
                }
                $at = array_search('--memory', $argv, true);
                $this->memoryFlags[] = $at === false ? null : $argv[$at + 1];

                $probe = fopen(storage_path('host-build.lock'), 'c');
                $free = flock($probe, LOCK_EX | LOCK_NB);
                if ($free) {
                    flock($probe, LOCK_UN);
                }
                fclose($probe);
                $this->slotFreeDuringBuild[] = $free;

                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };

        $model = new \App\Models\User();
        $model->username = $this->username;
        $model->details = ['UID' => 1001, 'GID' => 1001];
        $home = '/home/' . $this->username;

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn($builder ?? new DindHostBuilder($memory, ''));

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn($home . '/project');
        $dind->method('composeFilePath')->willReturn($home . '/docker-compose.yml');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount($this->username, $home, '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        $compile = new HostCompile($dind);
        for ($i = 0; $i < $times; $i++) {
            $compile->runPhpBuild(
                'panelalpha/php:8.3-apache-bookworm-pa20260910',
                ['install_command' => 'composer install --no-dev --no-interaction', 'build_command' => ''],
                '',
                true
            );
        }

        $this->memoryFlags = $system->memoryFlags;

        return $system->slotFreeDuringBuild;
    }

    /** @return list<string> */
    private function logLines(DeployLogger $logger): array
    {
        return array_map(static fn (array $entry): string => $entry['msg'], $logger->entries());
    }

    public function test_the_build_container_runs_holding_the_host_build_slot(): void
    {
        $this->assertSame([false], $this->build('5202m'));
    }

    public function test_a_streamed_build_holds_the_slot_too(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->assertSame([false, false], $this->build('5202m', 2));
    }

    public function test_the_deploy_log_says_what_the_build_container_gets_once(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->build('5202m', 2);

        $lines = array_values(array_filter(
            $this->logLines($logger),
            static fn (string $line): bool => str_starts_with($line, 'Host build container memory')
        ));
        $this->assertSame([HostCompile::buildMemoryLine(5202)], $lines);
        $this->assertStringContainsString('5202 MB', $lines[0]);
        $this->assertStringContainsString('DEPLOY_BUILD_MEMORY', $lines[0]);
        $this->assertStringContainsString('memory limit does not apply', $lines[0]);
    }

    public function test_the_logged_figure_is_the_one_the_container_is_started_with(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        // Unit-less is megabytes to us and bytes to Docker; the log must say what Docker got.
        $this->build('1024');

        $this->assertSame(['1024m'], $this->memoryFlags);
        $this->assertContains(HostCompile::buildMemoryLine(1024), $this->logLines($logger));
    }

    /** engine#295: unset, a build gets 8 GB once the host has 16 GB. */
    public function test_a_large_host_gives_the_build_8_gb(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->build('', 1, DindEngine::builderFor('', new HostMemory(32768)));

        $this->assertSame(['8192m'], $this->memoryFlags);
        $this->assertContains(
            'Host build container memory: 8192 MB, 8192 MB by default, at most half the server\'s 32768 MB and never more'
                . ' than its RAM less 512 MB for the engine (DEPLOY_ENGINE_MEMORY); DEPLOY_BUILD_MEMORY replaces the default,'
                . ' up to 16384 MB',
            $this->logLines($logger)
        );
    }

    /** engine#295: and never more than half the host. */
    public function test_a_small_host_holds_the_build_to_half_its_ram(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->build('', 1, DindEngine::builderFor('', new HostMemory(3790)));

        $this->assertSame(['1895m'], $this->memoryFlags);
        $this->assertContains(
            'Host build container memory: 1895 MB, 8192 MB by default, at most half the server\'s 3790 MB and never more'
                . ' than its RAM less 512 MB for the engine (DEPLOY_ENGINE_MEMORY); DEPLOY_BUILD_MEMORY replaces the default,'
                . ' up to 1895 MB',
            $this->logLines($logger)
        );
    }

    public function test_deploy_build_memory_wins_on_the_real_engine(): void
    {
        config(['deploy.build_memory' => '3g']);
        $builder = (new DindEngine())->hostBuilder();

        $this->assertSame(3072, $builder->memoryLimitMb());
        $this->assertSame(BuildMemory::SETTING, $builder->memoryOrigin()->source);
    }

    /** The real engine, reading this machine. */
    public function test_the_real_engine_reads_the_host_when_nothing_is_set(): void
    {
        config(['deploy.build_memory' => '']);
        $builder = (new DindEngine())->hostBuilder();

        $this->assertSame(
            min(DindEngine::MAX_BUILD_MEMORY_MB, DindEngine::buildCeilingMb(HostMemoryProbe::current())),
            $builder->memoryLimitMb()
        );
        $this->assertSame(BuildMemory::HOST, $builder->memoryOrigin()->source);
    }
}
