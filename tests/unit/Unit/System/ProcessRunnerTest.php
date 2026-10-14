<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Network;
use App\System\ProcessRunner;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\LiteSpeedStack;
use App\System\Firewall\Ufw\UfwFirewall;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Process\Process;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/**
 * The narrow view of App\System, for collaborators that only run commands.
 */
class ProcessRunnerTest extends TestCase
{
    /** @return list<class-string> */
    private const NARROWED = [
        Filesystem::class,
        Network::class,
        UfwFirewall::class,
        FpmStack::class,
        LiteSpeedStack::class,
        \App\Lib\Task\ProcessTreeKiller::class,
    ];

    /**
     * System itself implements it, so narrowing a consumer hands it the same
     * object it had before. That is what keeps the ~34 System subclasses in the
     * test suite intercepting what they always did.
     */
    public function test_system_is_a_process_runner(): void
    {
        $this->assertInstanceOf(ProcessRunner::class, new System());
    }

    public function test_a_system_subclass_is_still_a_process_runner(): void
    {
        $double = new class () extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['true']);
            }
        };

        $this->assertInstanceOf(ProcessRunner::class, $double);
    }

    public function test_the_interface_is_exactly_the_process_surface(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(ProcessRunner::class))->getMethods()
        );
        sort($methods);

        $this->assertSame([
            'exec',
            'execOnHost',
            'runProcess',
            'runProcessOnHost',
            'runProcessWithCallbacks',
        ], $methods);
    }

    /**
     * Anything wider -- a path, the filesystem, a service factory -- would make
     * this a second name for System rather than a narrowing.
     */
    public function test_the_interface_carries_nothing_but_process_methods(): void
    {
        foreach ((new ReflectionClass(ProcessRunner::class))->getMethods() as $method) {
            $this->assertMatchesRegularExpression(
                '/^(exec|run)/',
                $method->getName(),
                $method->getName() . ' is not a way of running a command'
            );
        }
    }

    public function test_the_narrowed_collaborators_ask_only_for_a_process_runner(): void
    {
        foreach (self::NARROWED as $class) {
            $parameter = (new ReflectionClass($class))->getConstructor()->getParameters()[0];
            $type = $parameter->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type, $class);
            $this->assertSame(
                ProcessRunner::class,
                $type->getName(),
                $class . ' should ask for the narrow dependency, not the whole System'
            );
        }
    }

    /** Each of them is still constructed with a System, unchanged. */
    public function test_a_system_still_satisfies_every_narrowed_constructor(): void
    {
        $system = new System();

        $this->assertInstanceOf(Filesystem::class, new Filesystem($system));
        $this->assertInstanceOf(Network::class, new Network($system));
        $this->assertInstanceOf(UfwFirewall::class, new UfwFirewall($system));
        $this->assertInstanceOf(\App\Lib\Task\ProcessTreeKiller::class, new \App\Lib\Task\ProcessTreeKiller($system));
    }

    /**
     * The point of the narrowing: a collaborator can now be given a fake that
     * is a few lines, instead of a subclass of a 48-method facade.
     */
    public function test_a_narrowed_collaborator_accepts_a_small_fake(): void
    {
        $runner = new RecordingRunner();

        $killer = new \App\Lib\Task\ProcessTreeKiller($runner);
        $killer->kill(4321);

        $this->assertNotSame([], $runner->commands, 'the fake saw the command');
        $this->assertNotInstanceOf(System::class, $runner, 'no System subclass was needed');
    }
}
