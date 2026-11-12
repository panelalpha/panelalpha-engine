<?php

namespace Tests\Unit\System;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Running commands, and the delegation chain about thirty test files depend on.
 *
 * Those files subclass App\System and override one or another of exec(),
 * execOnHost(), runProcess() and runProcessOnHost() so a test never runs a real
 * command. That only works while System's methods call each other virtually, so
 * the chain is asserted here rather than left to be discovered by a suite that
 * suddenly starts shelling out.
 */
class HostProcessTest extends TestCase
{
    // --- only the gateway's commands reach the host ---

    public function test_a_gateway_command_is_sent_to_the_host_as_the_gateway_built_it(): void
    {
        $system = new RecordingHostSystem;

        $system->runProcessOnHost(Privileged::users()->uid('alice'));
        $system->runProcessOnHost(Privileged::docker()->info());

        $this->assertSame([
            self::onHost(Privileged::users()->uid('alice')),
            self::onHost(Privileged::docker()->info()),
        ], $system->seen);
        $this->assertSame(['id', '-u', 'alice'], LocalRoot::plain($system->seen[0]));
    }

    public function test_a_command_of_the_callers_own_never_reaches_the_host(): void
    {
        $system = new RecordingHostSystem;

        foreach ([['id', '-u', 'alice'], ['nsenter', '--target', '1', '--all', 'sh'], []] as $command) {
            try {
                $system->execOnHost($command);
                $this->fail('ran '.implode(' ', $command).' on the host');
            } catch (InvalidArgument) {
            }
        }
        $this->assertSame([], $system->seen);
    }

    // --- exec() error mapping ---

    public function test_a_successful_command_returns_its_stdout(): void
    {
        $system = new FakeProcessSystem(FakeProcess::ok('hello'));

        $this->assertSame('hello', $system->exec(['echo', 'hello']));
    }

    /** A daemon error is 422 or 503 at the HTTP boundary, not 500. */
    public function test_a_docker_daemon_error_is_its_own_exception(): void
    {
        $system = new FakeProcessSystem(
            FakeProcess::failed('Error response from daemon: no such container')
        );

        $this->expectException(DockerErrorException::class);
        $system->exec(['docker', 'ps']);
    }

    public function test_any_other_failure_is_a_plain_exception(): void
    {
        $system = new FakeProcessSystem(FakeProcess::failed('chmod: no such file'));

        try {
            $system->exec(['chmod', '600', '/nope']);
            $this->fail('Expected an exception');
        } catch (DockerErrorException $e) {
            $this->fail('A plain failure must not be reported as a docker error');
        } catch (\Exception $e) {
            $this->assertSame('chmod: no such file', $e->getMessage());
        }
    }

    /** Some tools report the reason on stdout and leave stderr empty. */
    public function test_stdout_is_used_when_the_failure_wrote_nothing_to_stderr(): void
    {
        $system = new FakeProcessSystem(FakeProcess::of(1, 'it went wrong', ''));

        $this->expectExceptionMessage('it went wrong');
        $system->exec(['something']);
    }

    // --- the delegation chain ---

    public function test_overriding_run_process_alone_also_changes_exec(): void
    {
        $system = new class () extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::ok('faked');
            }
        };

        $this->assertSame('faked', $system->exec(['whoami']), 'exec() must go through runProcess()');
    }

    public function test_overriding_run_process_alone_also_changes_exec_on_host(): void
    {
        $system = new class () extends System {
            /** @var list<string|array> */
            public array $seen = [];

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->seen[] = $cmd;

                return FakeProcess::ok('faked');
            }
        };

        $this->assertSame('faked', $system->execOnHost(Privileged::probes()->gitVersion()));
        $this->assertSame(
            [self::onHost(Privileged::probes()->gitVersion())],
            $system->seen
        );
    }

    public function test_overriding_exec_alone_also_changes_exec_on_host(): void
    {
        $system = new class () extends System {
            /** @var list<string|array> */
            public array $seen = [];

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->seen[] = $cmd;

                return 'intercepted';
            }
        };

        $this->assertSame('intercepted', $system->execOnHost(Privileged::probes()->gitVersion()), 'execOnHost() must go through exec()');
        $this->assertSame([self::onHost(Privileged::probes()->gitVersion())], $system->seen);
    }

    public function test_overriding_run_process_alone_also_changes_run_process_on_host(): void
    {
        $system = new class () extends System {
            /** @var list<string|array> */
            public array $seen = [];

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->seen[] = $cmd;

                return FakeProcess::ok();
            }
        };

        $system->runProcessOnHost(Privileged::host()->processExists(42));

        $this->assertSame(
            [self::onHost(Privileged::host()->processExists(42))],
            $system->seen
        );
        $this->assertSame(['kill', '-0', '--', '42'], LocalRoot::plain($system->seen[0]));
    }

    /**
     * The whole point of the chain: a subclass that fakes one method must not
     * leave another quietly running the real command.
     */
    public function test_no_real_command_escapes_a_subclass_that_fakes_run_process(): void
    {
        $system = new class () extends System {
            public bool $ranSomething = false;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ranSomething = true;

                return FakeProcess::ok();
            }
        };

        $system->exec(['true']);
        $system->execOnHost(Privileged::probes()->gitVersion());
        $system->runProcessOnHost(Privileged::probes()->gitVersion());

        $this->assertTrue($system->ranSomething);
    }

    public function test_the_timeout_and_environment_reach_run_process(): void
    {
        $system = new class () extends System {
            public array $env = [];
            public int $timeout = 0;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->env = $env;
                $this->timeout = $timeout;

                return FakeProcess::ok();
            }
        };

        $system->exec(['true'], ['FOO' => 'bar'], 42);

        $this->assertSame(['FOO' => 'bar'], $system->env);
        $this->assertSame(42, $system->timeout);
    }

    // --- building the process ---

    public function test_a_list_is_run_as_an_argv_and_a_string_through_a_shell(): void
    {
        $system = new System();

        $this->assertSame("'a b'", trim($system->exec(['echo', "'a b'"])), 'a list is not touched by a shell');
        $this->assertSame('a b', trim($system->exec("echo 'a b'")), 'a string is a shell line');
    }

    /**
     * @param  list<string>  $request
     * @return list<string>
     */
    private static function onHost(array $request): array
    {
        return $request[1] === '--host' ? $request : [LocalRoot::CLIENT, '--host', ...array_slice($request, 1)];
    }

    public function test_a_failing_real_command_throws_with_its_stderr(): void
    {
        $system = new System();

        try {
            $system->exec(['sh', '-c', 'echo nope >&2; exit 1']);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            // Named rather than expectException(): that would also swallow a
            // RuntimeException from an unbooted facade and pass for the wrong reason.
            $this->assertSame('nope', trim($e->getMessage()));
        }
    }
}

/** @internal */
final class RecordingHostSystem extends System
{
    /** @var list<string|list<string>> */
    public array $seen = [];

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->seen[] = $cmd;

        return FakeProcess::ok();
    }
}

/** @internal */
final class FakeProcessSystem extends System
{
    public function __construct(private FakeProcess $result)
    {
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->result;
    }
}
