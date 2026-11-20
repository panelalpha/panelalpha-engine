<?php

namespace Tests\Integration\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * A stopped container is docker's failure whether the command ran quietly ({@see System::exec()}), under a deploy's
 * cancel ({@see ShellOperations::execQuietUntilCancelled()}) or streamed into its log ({@see ShellOperations::exec()}).
 */
class DockerFailureOfEitherRunnerTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'dkfail-' . bin2hex(random_bytes(4));
        config(['deploy.step_idle_timeout' => 0]);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** @return array<string, array{string, bool}> */
    public static function failures(): array
    {
        return [
            'the daemon refused' => ["Error response from daemon: No such container: alice-dind-1\n", true],
            'a stopped compose service' => ["service \"dind\" is not running\n", true],
            'a restarting container' => ["Container 3f2a9c is restarting, wait until the container is running\n", true],
            'the command itself failed' => ["npm ERR! Missing script: \"build\"\n", false],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_quiet_command_tells_a_docker_failure_from_its_own(string $output, bool $dockers): void
    {
        $failure = $this->failureOf(fn () => (new FailingSystem($output))->exec(['docker', 'ps']));

        $this->assertSame($dockers, $failure instanceof DockerErrorException, $failure::class);
        $this->assertSame($output, $failure->getMessage());
    }

    #[DataProvider('failures')]
    public function test_a_streamed_command_tells_a_docker_failure_from_its_own(string $output, bool $dockers): void
    {
        $logger = DeployLogger::start($this->username);

        $failure = $this->failureOf(fn () => $this->shell(new FailingSystem($output))->exec(['docker', 'compose', 'up', '-d']));

        $this->assertSame($dockers, $failure instanceof DockerErrorException, $failure::class);
        $this->assertSame($output, $failure->getMessage());
        $logger->finish(DeployLogger::STATUS_FAILED, 'done');
    }

    #[DataProvider('failures')]
    public function test_a_command_the_deploy_cancel_can_stop_tells_a_docker_failure_from_its_own(string $output, bool $dockers): void
    {
        $logger = DeployLogger::start($this->username);

        $failure = $this->failureOf(fn () => $this->shell(new FailingSystem($output))->execQuietUntilCancelled(['bash', '-c', 'true'], $logger));

        $this->assertSame($dockers, $failure instanceof DockerErrorException, $failure::class);
        $this->assertSame($output, $failure->getMessage());
        $logger->finish(DeployLogger::STATUS_FAILED, 'done');
    }

    private function failureOf(callable $run): \Exception
    {
        try {
            $run();
        } catch (\Exception $e) {
            return $e;
        }
        $this->fail('The command did not fail.');
    }

    private function shell(System $system): ShellOperations
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('username')->andReturn($this->username);
        $dind->shouldReceive('composeFilePath')->andReturn('/opt/panelalpha/shared-hosting/users/' . $this->username . '/docker-compose.yml');
        $dind->shouldReceive('homeDirPath')->andReturn('/home/' . $this->username);
        $dind->shouldReceive('system')->andReturn($system);

        return new ShellOperations($dind);
    }
}

/** @internal Every command fails with the output it was given, on stderr. */
final class FailingSystem extends System
{
    public function __construct(private readonly string $output)
    {
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return FakeProcess::failed($this->output);
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        return FakeProcess::failed($this->output);
    }
}
