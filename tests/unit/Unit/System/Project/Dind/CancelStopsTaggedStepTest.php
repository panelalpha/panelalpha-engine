<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A cancel kills the `docker compose exec` on the host, which leaves the step
 * it started inside the account running: a build that then succeeds could
 * start an orphaned container. The cancel stops the tagged step too.
 */
class CancelStopsTaggedStepTest extends TestCase
{
    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'cst' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** @param list<list<string>> $streamed @param list<list<string>> $stopped */
    private function dind(array &$streamed, array &$stopped, bool $cancel): Dind
    {
        $model = new ModelsUser;
        $model->username = $this->username;
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        $username = $this->username;
        $system = new class ($streamed, $stopped, $cancel, $username) extends System
        {
            public function __construct(private array &$streamed, private array &$stopped, private bool $cancel, private string $username) {}

            public function homesDirPath(): string
            {
                return '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?\App\Lib\Deploy\DeployLog\StepWatchdog $watchdog = null): Process
            {
                $this->streamed[] = (array) $cmd;
                if ($this->cancel) {
                    // What POST /tasks/N/cancel does while the step runs: the exec dies.
                    DeployLogger::requestCancel($this->username);
                }
                $process = new Process([$this->cancel ? 'false' : 'true']);
                $process->run();

                return $process;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->stopped[] = (array) $cmd;
                $process = new Process(['true']);
                $process->run();

                return $process;
            }
        };

        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    public function test_a_cancelled_step_is_stopped_inside_the_account(): void
    {
        config(['deploy.step_idle_timeout' => 900]);
        $streamed = $stopped = [];
        $dind = $this->dind($streamed, $stopped, cancel: true);
        $logger = DeployLogger::start($this->username);

        try {
            $dind->shell()->exec(['docker', 'compose', 'build']);
            $this->fail('The cancel was not reported.');
        } catch (DeployCancelledException) {
        } finally {
            $logger->finish(DeployLogger::STATUS_CANCELLED);
        }

        $tagged = array_values(array_filter($streamed[0], static fn (string $a): bool => str_starts_with($a, ShellOperations::STEP_ENV . '=')));
        $this->assertCount(1, $tagged, 'the step carried its tag');
        $this->assertCount(1, $stopped);
        $this->assertSame($tagged[0], end($stopped[0]), 'and that tag was killed inside the account');
    }

    public function test_a_step_that_was_not_cancelled_is_left_alone(): void
    {
        config(['deploy.step_idle_timeout' => 900]);
        $streamed = $stopped = [];
        $dind = $this->dind($streamed, $stopped, cancel: false);
        $logger = DeployLogger::start($this->username);

        try {
            $dind->shell()->exec(['docker', 'compose', 'build']);
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertSame([], $stopped);
    }
}
