<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * engine#184: every host build container is sized for the server, raised
 * (never lowered) by a larger project limit, so the builds run one at a time
 * and the deploy log says what the container got and why. The slot was dropped when HostCompile moved to System and
 * nothing called it, so QUEUE_WORKERS builds could each take a third of RAM.
 */
class HostCompileBuildSlotTest extends TestCase
{
    private string $username = '';

    /** @var list<?string> */
    private array $memoryFlags = [];

    /** A 15.6 GB host, the test host's own MemTotal: server share 5202 MB, half 7804 MB. */
    private const MEMINFO_16G = "MemTotal:       15983292 kB\n";

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
    private function build(string $memory, int $times = 1, ?int $projectMemoryMb = null, ?callable $builderFor = null): array
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
        $model->details = ['UID' => 1001, 'GID' => 1001, 'memory_limit' => $projectMemoryMb];
        $home = '/home/' . $this->username;

        $engine = $this->createStub(ContainerEngine::class);
        if ($builderFor === null) {
            $engine->method('hostBuilder')->willReturn(new DindHostBuilder($memory, ''));
        } else {
            $engine->method('hostBuilder')->willReturnCallback($builderFor);
        }

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

        // Below the builder's floor: the container gets 2048, so the log must too.
        $this->build('1g');

        $this->assertContains(HostCompile::buildMemoryLine(2048), $this->logLines($logger));
    }

    /**
     * Option B of engine#184: a project given more memory than the server
     * share gets a build that can use it, and the log says where it came from.
     */
    public function test_a_larger_project_limit_raises_the_build_container(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $asked = [];

        $this->build('', 1, 7000, function (?int $projectMemoryMb) use (&$asked): DindHostBuilder {
            $asked[] = $projectMemoryMb;

            return DindEngine::builderFor('', self::MEMINFO_16G, $projectMemoryMb);
        });

        $this->assertContains(7000, $asked, 'the project\'s memory limit must reach the engine');
        $this->assertSame(['7000m'], $this->memoryFlags);
        $line = HostCompile::buildMemoryLine(7000, DindEngine::buildMemory('', self::MEMINFO_16G, 7000));
        $this->assertContains($line, $this->logLines($logger));
        $this->assertStringContainsString('raised to the project\'s memory limit from the server default of 5202 MB', $line);
    }

    public function test_a_smaller_project_limit_does_not_lower_it(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->build('', 1, 2000, static fn (?int $mb): DindHostBuilder => DindEngine::builderFor('', self::MEMINFO_16G, $mb));

        $this->assertSame(['5202m'], $this->memoryFlags);
        $line = HostCompile::buildMemoryLine(5202, DindEngine::buildMemory('', self::MEMINFO_16G, 2000));
        $this->assertContains($line, $this->logLines($logger));
        $this->assertStringContainsString('the server default', $line);
    }

    public function test_the_project_cannot_take_more_than_half_the_server(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->build('', 1, 12000, static fn (?int $mb): DindHostBuilder => DindEngine::builderFor('', self::MEMINFO_16G, $mb));

        $this->assertSame(['7804m'], $this->memoryFlags);
        $this->assertContains(
            'Host build container memory: 7804 MB, the project\'s memory limit of 12000 MB held to half the server\'s RAM'
                . ' (server default 5202 MB); DEPLOY_BUILD_MEMORY replaces both',
            $this->logLines($logger)
        );
    }

    /** The operator's number is a decision for the server; a plan does not undo it. */
    public function test_deploy_build_memory_wins_over_the_project_on_the_real_engine(): void
    {
        config(['deploy.build_memory' => '3g']);
        $builder = (new DindEngine())->hostBuilder(12000);

        $this->assertSame(3072, $builder->memoryLimitMb());
        $this->assertSame(BuildMemory::SETTING, $builder->memoryOrigin()->source);
    }

    /** The real engine, reading this machine: a project can only ever raise the build, and never past half. */
    public function test_the_real_engine_reads_the_project_limit_when_nothing_is_set(): void
    {
        config(['deploy.build_memory' => '']);
        $engine = new DindEngine();
        $server = $engine->hostBuilder(null)->memoryLimitMb();
        preg_match('/^MemTotal:\s+(\d+)/m', (string) file_get_contents('/proc/meminfo'), $m);
        $halfMb = intdiv(intdiv((int) $m[1], 1024), 2);

        $this->assertSame($server, $engine->hostBuilder(1)->memoryLimitMb());
        $huge = $engine->hostBuilder(PHP_INT_MAX);
        $this->assertSame(max($server, $halfMb), $huge->memoryLimitMb());
        $this->assertNotSame(BuildMemory::SETTING, $huge->memoryOrigin()->source);
    }
}
