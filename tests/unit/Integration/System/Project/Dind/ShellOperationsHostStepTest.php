<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\BuildStalledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Privileged;
use App\System\Privileged\BuildContainer;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Integration\System\Project\SuccessfulProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A step that runs as a host build container is watched and stopped by the containers carrying its tag;
 * a command that is neither a DinD exec nor a host build is never busy.
 */
class ShellOperationsHostStepTest extends TestCase
{
    private string $username = '';

    /** @var list<list<string>> */
    private array $execs = [];

    /** @var list<list<string>> */
    private array $processes = [];

    private string $tagged = '';

    private ?\Throwable $listFails = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'shhost'.bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    public function test_a_host_build_is_busy_by_the_cpu_of_its_tagged_containers(): void
    {
        $this->tagged = "c1\nc2\n";

        $this->assertTrue($this->shell()->stepIsBusy($this->hostBuild(), 'abc'));

        $this->assertSame([['abc'], ['c1', 'c2']], [
            array_slice(LocalRoot::values($this->execs[0]), 1),
            LocalRoot::values($this->execs[1]),
        ]);
        $this->assertSame('docker.cpuPercents', LocalRoot::action($this->execs[1]));
    }

    public function test_a_host_build_with_no_container_running_is_idle(): void
    {
        $this->assertFalse($this->shell()->stepIsBusy($this->hostBuild(), 'abc'));

        $this->assertSame(['docker.runningContainerIdsWithLabel'], array_map(LocalRoot::action(...), $this->execs));
    }

    public function test_any_other_command_is_never_busy(): void
    {
        $this->assertFalse($this->shell()->stepIsBusy(['true'], 'abc'));
        $this->assertSame([], $this->execs);
    }

    public function test_a_stalled_host_build_has_its_tagged_containers_removed(): void
    {
        $this->tagged = "c9\n";
        $logger = DeployLogger::start($this->username);

        try {
            $this->shell()->streamProcess($this->hostBuild(), [], 60, $logger);
            $this->fail('the stalled step is thrown');
        } catch (BuildStalledException $e) {
            $this->assertSame('no output for 900s', $e->getMessage());
        }

        $removed = array_values(array_filter($this->processes, static fn (array $argv): bool => LocalRoot::action($argv) === 'docker.forceRemoveContainers'));
        $this->assertCount(1, $removed);
        $this->assertSame(['c9'], LocalRoot::values($removed[0]));
    }

    public function test_a_stalled_host_build_whose_containers_cannot_be_listed_is_reported(): void
    {
        $this->listFails = new \RuntimeException('docker is not running');
        $logger = DeployLogger::start($this->username);

        try {
            $this->shell()->streamProcess($this->hostBuild(), [], 60, $logger);
            $this->fail('the stalled step is thrown');
        } catch (BuildStalledException) {
        }

        $this->assertContains(
            'Could not stop what the step left running: docker is not running',
            array_column($logger->entries(), 'msg')
        );
        $this->assertSame([], array_filter($this->processes, static fn (array $argv): bool => LocalRoot::action($argv) === 'docker.forceRemoveContainers'));
    }

    /** @return list<string> */
    private function hostBuild(): array
    {
        return Privileged::docker()->nodeBuild(
            new BuildContainer('1001:1001', '2g', null, ['/app' => '/home/'.$this->username.'/project'], [], '/app', [], 'node:22'),
            'npm ci'
        );
    }

    private function shell(): ShellOperations
    {
        $harness = $this;
        $system = new class($harness) extends System
        {
            public function __construct(private object $harness) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->harness->answerExec(array_values((array) $cmd));
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->harness->answerProcess(array_values((array) $cmd));
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                throw new BuildStalledException('no output for 900s');
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('homeDirPath')->willReturn('/home/'.$this->username);
        $dind->method('composeFilePath')->willReturn('/opt/users/'.$this->username.'/docker-compose.yml');
        $dind->method('system')->willReturn($system);

        return new ShellOperations($dind);
    }

    /** @param list<string> $argv */
    public function answerExec(array $argv): string
    {
        $this->execs[] = $argv;
        if (LocalRoot::action($argv) === 'docker.runningContainerIdsWithLabel') {
            if ($this->listFails !== null) {
                throw $this->listFails;
            }

            return $this->tagged;
        }

        return LocalRoot::action($argv) === 'docker.cpuPercents' ? "60.00%\n50.00%\n" : '';
    }

    /** @param list<string> $argv */
    public function answerProcess(array $argv): Process
    {
        $this->processes[] = $argv;

        return SuccessfulProcess::for($argv);
    }
}
