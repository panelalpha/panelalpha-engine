<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A cancel kills the exec on the host, which leaves the step
 * it started inside the account running: a build that then succeeds could
 * start an orphaned container. The cancel stops the tagged step too. The
 * broker stops a docker client it runs once its caller is gone.
 */
class CancelStopsTaggedStepTest extends TestCase
{
    private const STEP = ['sh', '-c', 'npm ci'];

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

    /**
     * With $signalled the step ends by SIGTERM, as the cancel's kill of its exec ends it, rather than by exiting.
     *
     * @param list<list<string>> $streamed @param list<list<string>> $stopped
     */
    private function dind(array &$streamed, array &$stopped, bool $cancel, bool $signalled = false): Dind
    {
        $model = new ModelsUser;
        $model->username = $this->username;
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        $username = $this->username;
        $system = new class ($streamed, $stopped, $cancel, $signalled, $username) extends System
        {
            public function __construct(private array &$streamed, private array &$stopped, private bool $cancel, private bool $signalled, private string $username) {}

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
                $process = new Process($this->signalled ? ['sh', '-c', 'kill -TERM $$'] : [$this->cancel ? 'false' : 'true']);
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

    /** @return array<string, array{bool}> */
    public static function cancelledSteps(): array
    {
        return ['a step that exits' => [false], 'a step the signal ended' => [true]];
    }

    #[DataProvider('cancelledSteps')]
    public function test_a_cancelled_step_is_stopped_inside_the_account(bool $signalled): void
    {
        config(['deploy.step_idle_timeout' => 900]);
        $streamed = $stopped = [];
        $dind = $this->dind($streamed, $stopped, cancel: true, signalled: $signalled);
        $logger = DeployLogger::start($this->username);

        try {
            $dind->shell()->exec(self::STEP);
            $this->fail('The cancel was not reported.');
        } catch (DeployCancelledException) {
        } finally {
            $logger->finish(DeployLogger::STATUS_CANCELLED);
        }

        $tag = LocalRoot::accountEnvironment($streamed[0])[ShellOperations::STEP_ENV] ?? null;
        $this->assertNotNull($tag, 'the step carried its tag');
        $this->assertCount(1, $stopped);
        $kill = LocalRoot::inAccount($stopped[0]);
        $this->assertSame(ShellOperations::STEP_ENV . '=' . $tag, end($kill), 'and that tag was killed inside the account');
    }

    public function test_a_cancelled_docker_client_is_left_to_the_broker(): void
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

        $this->assertSame(['docker', 'compose', 'build'], LocalRoot::composeCommand($streamed[0]));
        $this->assertSame([], $stopped);
    }

    public function test_a_step_that_was_not_cancelled_is_left_alone(): void
    {
        config(['deploy.step_idle_timeout' => 900]);
        $streamed = $stopped = [];
        $dind = $this->dind($streamed, $stopped, cancel: false);
        $logger = DeployLogger::start($this->username);

        try {
            $dind->shell()->exec(self::STEP);
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertSame([], $stopped);
    }

    public function test_a_signal_that_is_no_cancel_still_fails_the_step(): void
    {
        config(['deploy.step_idle_timeout' => 900]);
        $streamed = $stopped = [];
        $dind = $this->dind($streamed, $stopped, cancel: false, signalled: true);
        $logger = DeployLogger::start($this->username);

        try {
            $dind->shell()->exec(self::STEP);
            $this->fail('The signal was not reported.');
        } catch (ProcessSignaledException) {
        } finally {
            $logger->finish(DeployLogger::STATUS_FAILED);
        }

        $this->assertSame([], $stopped);
    }
}
