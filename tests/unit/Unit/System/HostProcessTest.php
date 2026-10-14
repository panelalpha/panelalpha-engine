<?php

namespace Tests\Unit\System;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\HostProcess;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
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
    // --- the nsenter prefix ---

    public function test_a_list_stays_a_list_when_it_is_sent_to_the_host(): void
    {
        $this->assertSame(
            ['sudo', 'nsenter', '--target', '1', '--all', 'id', '-u', 'alice'],
            HostProcess::onHost(['id', '-u', 'alice'])
        );
    }

    /**
     * A caller that passed a shell line meant a shell line; turning it into an
     * argv here would change how it is quoted.
     */
    public function test_a_shell_line_stays_a_shell_line(): void
    {
        $this->assertSame(
            'sudo nsenter --target 1 --all ls /tmp | wc -l',
            HostProcess::onHost('ls /tmp | wc -l')
        );
    }

    public function test_an_empty_list_is_just_the_prefix(): void
    {
        $this->assertSame(['sudo', 'nsenter', '--target', '1', '--all'], HostProcess::onHost([]));
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

        $this->assertSame('faked', $system->execOnHost(['whoami']));
        $this->assertSame(
            [['sudo', 'nsenter', '--target', '1', '--all', 'whoami']],
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

        $this->assertSame('intercepted', $system->execOnHost('ls /tmp'), 'execOnHost() must go through exec()');
        $this->assertSame(['sudo nsenter --target 1 --all ls /tmp'], $system->seen);
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

        $system->runProcessOnHost(['kill', '-0', '42']);

        $this->assertSame(
            [['sudo', 'nsenter', '--target', '1', '--all', 'kill', '-0', '42']],
            $system->seen
        );
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
        $system->execOnHost(['true']);
        $system->runProcessOnHost(['true']);

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
