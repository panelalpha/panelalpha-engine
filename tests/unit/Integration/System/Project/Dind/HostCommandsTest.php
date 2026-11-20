<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Background host work is best effort: the deploy goes on, and one that did not start says why in its log. */
class HostCommandsTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'hostcmd'.bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    public function test_work_that_started_logs_nothing(): void
    {
        $logger = $this->runningDeploy();

        $this->commands(['php', '-r', 'exit(0);'])->inBackground(['true']);

        $this->assertSame([], $this->warnings($logger));
    }

    public function test_a_refused_request_is_a_warning_with_the_refusal(): void
    {
        $logger = $this->runningDeploy();

        $this->commands(['php', '-r', 'fwrite(STDERR, "refused: not an image the host builds\n"); exit(3);'])
            ->inBackground(['true']);

        $this->assertSame(
            ['Background host work did not start; the next deploy tries again: refused: not an image the host builds'],
            $this->warnings($logger)
        );
    }

    public function test_a_start_that_times_out_is_a_warning_and_the_deploy_goes_on(): void
    {
        $logger = $this->runningDeploy();
        $timedOut = new Process(['sleep', '1']);
        $timedOut->setTimeout(0.01);

        $this->commands(new ProcessTimedOutException($timedOut, ProcessTimedOutException::TYPE_GENERAL))
            ->inBackground(['true']);

        $this->assertSame(
            ['Background host work did not start; the next deploy tries again: The process "\'sleep\' \'1\'" exceeded the timeout of 0.01 seconds.'],
            $this->warnings($logger)
        );
    }

    private function runningDeploy(): DeployLogger
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        return $logger;
    }

    /** @return list<string> */
    private function warnings(DeployLogger $logger): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['msg'],
            array_filter($logger->entries(), static fn (array $entry): bool => $entry['level'] === DeployLogger::LEVEL_WARN)
        ));
    }

    /**
     * @param  list<string>|\Exception  $outcome  the process the request runs as, or how starting it fails
     */
    private function commands(array|\Exception $outcome): HostCommands
    {
        $system = new class($outcome) extends System
        {
            public function __construct(private array|\Exception $outcome) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if ($this->outcome instanceof \Exception) {
                    throw $this->outcome;
                }
                $process = new Process($this->outcome);
                $process->run();

                return $process;
            }
        };
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);

        return new HostCommands($inner);
    }
}
