<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

final class RootAccountContainerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    public function test_an_exec_names_the_account_first_then_user_workdir_and_environment(): void
    {
        $this->assertSame(
            [self::CLIENT, 'account-container.exec', '--', 'alice', '1000:1000', '/home/alice/project', '1', 'HOME', '/home/alice', 'id', '-u'],
            Privileged::accountContainer('alice')->exec(['id', '-u'], '1000:1000', '/home/alice/project', ['HOME' => '/home/alice'])
        );
    }

    public function test_an_exec_as_the_default_user_leaves_them_empty(): void
    {
        $this->assertSame(
            [self::CLIENT, 'account-container.exec', '--', 'alice', '', '', '0', 'sh', '-c', 'echo "$1"', 'sh', 'a b'],
            Privileged::accountContainer('alice')->exec(['sh', '-c', 'echo "$1"', 'sh', 'a b'])
        );
    }

    public function test_the_broker_takes_it_as_an_engine_api_call_it_makes_itself(): void
    {
        $exec = Privileged::accountContainer('alice')->exec(['true']);

        $this->assertSame(['pa-root', ...array_slice($exec, 1)], LocalRoot::plain($exec));
    }

    public function test_an_exec_is_known_only_for_its_own_account(): void
    {
        $exec = Privileged::accountContainer('alice')->exec(['true']);

        $this->assertTrue(Privileged::accountContainer('alice')->isExec($exec));
        $this->assertFalse(Privileged::accountContainer('bob')->isExec($exec));
        $this->assertFalse(Privileged::accountContainer('alice')->isExec(Privileged::accountDocker('alice')->exec('alice', ['true'])));
    }

    public function test_an_added_variable_comes_first_and_the_command_is_unchanged(): void
    {
        $container = Privileged::accountContainer('alice');
        $exec = $container->exec(['docker', 'compose', 'build'], environment: ['DOCKER_CONFIG' => '/home/alice/.panelalpha-docker']);

        $tagged = $container->withEnvironment($exec, 'PANELALPHA_STEP', 'abc');

        $this->assertSame(['PANELALPHA_STEP' => 'abc', 'DOCKER_CONFIG' => '/home/alice/.panelalpha-docker'], LocalRoot::accountEnvironment($tagged));
        $this->assertSame(['docker', 'compose', 'build'], LocalRoot::inAccount($tagged));
    }

    public function test_another_accounts_exec_takes_no_variable(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::accountContainer('alice')->withEnvironment(Privileged::accountContainer('bob')->exec(['true']), 'A', 'b');
    }

    public function test_a_registry_refresh_asks_about_the_account_s_own_container(): void
    {
        $container = Privileged::accountContainer('alice');

        $this->assertSame(
            [self::CLIENT, 'account-container.daemonConfigMounted', '--', 'alice'],
            $container->daemonConfigMounted()
        );
        $this->assertSame([self::CLIENT, 'account-container.reloadDaemon', '--', 'alice'], $container->reloadDaemon());
    }

    public function test_a_registry_refresh_is_no_host_request(): void
    {
        $container = Privileged::accountContainer('alice');

        $this->assertSame(['pa-root', 'account-container.daemonConfigMounted', '--', 'alice'], LocalRoot::plain($container->daemonConfigMounted()));
        $this->assertSame(['pa-root', 'account-container.reloadDaemon', '--', 'alice'], LocalRoot::plain($container->reloadDaemon()));
    }

    public function test_a_shell_step_reads_as_its_command(): void
    {
        $exec = Privileged::accountContainer('alice')->exec(['su', '-s', '/bin/bash', 'alice', '-c', 'npm ci'], environment: ['A' => 'b']);

        $this->assertSame('npm ci', ShellOperations::stepLabel($exec));
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refused(): iterable
    {
        yield 'username that is a path' => [fn () => Privileged::accountContainer('../bob')->exec(['true'])];
        yield 'daemon config of a username that is a path' => [fn () => Privileged::accountContainer('../bob')->daemonConfigMounted()];
        yield 'reload of a username that is an option' => [fn () => Privileged::accountContainer('-a')->reloadDaemon()];
        yield 'nothing to run' => [fn () => Privileged::accountContainer('alice')->exec([])];
        yield 'user that is an option' => [fn () => Privileged::accountContainer('alice')->exec(['id'], '-u0')];
        yield 'relative workdir' => [fn () => Privileged::accountContainer('alice')->exec(['id'], '', 'project')];
        yield 'environment name with a space' => [fn () => Privileged::accountContainer('alice')->exec(['id'], environment: ['A B' => 'x'])];
        yield 'NUL in the command' => [fn () => Privileged::accountContainer('alice')->exec(["a\0b"])];
        yield 'NUL in a value' => [fn () => Privileged::accountContainer('alice')->exec(['id'], environment: ['A' => "x\0"])];
    }

    /** @param  \Closure(): mixed  $build */
    #[DataProvider('refused')]
    public function test_a_value_the_broker_would_refuse_is_refused_before_it_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }
}
