<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootAccountCompose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RootAccountComposeTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    public function test_a_run_names_the_account_then_the_workdir_the_environment_and_the_client(): void
    {
        $this->assertSame(
            [self::CLIENT, 'account-compose.run', '--', 'alice', '', '/home/alice/project', '1', 'PWD', '/home/alice/project', 'compose', 'ps', '--quiet'],
            Privileged::accountCompose('alice')->run(['compose', 'ps', '--quiet'], '/home/alice/project', ['PWD' => '/home/alice/project'])
        );
    }

    public function test_a_compose_line_becomes_a_run_with_what_env_sets_over_the_given_environment(): void
    {
        $run = Privileged::accountCompose('alice')->forCommand(
            ['env', 'PWD=/home/alice/project', 'DOCKER_CONFIG=/home/alice/.panelalpha-registry-x', 'docker', 'compose', '--project-directory', '/home/alice/project', 'up', '-d'],
            ['DOCKER_CONFIG' => '/home/alice/.panelalpha-docker'],
            true
        );

        $this->assertSame([
            self::CLIENT, 'account-compose.run', '--', 'alice', 'root', '/home/alice/project',
            '2', 'DOCKER_CONFIG', '/home/alice/.panelalpha-registry-x', 'PWD', '/home/alice/project',
            'compose', '--project-directory', '/home/alice/project', 'up', '-d',
        ], $run);
        $this->assertTrue(RootAccountCompose::isRunRequest((array) $run));
        $this->assertSame(['compose', '--project-directory', '/home/alice/project', 'up', '-d'], RootAccountCompose::commandOf((array) $run));
    }

    public function test_a_buildx_build_without_env_runs_in_the_home(): void
    {
        $this->assertSame(
            [self::CLIENT, 'account-compose.run', '--', 'alice', '', '', '0', 'buildx', 'build', '.'],
            Privileged::accountCompose('alice')->forCommand(['docker', 'buildx', 'build', '.'])
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function otherCommands(): iterable
    {
        yield 'another docker verb' => [['docker', 'ps']];
        yield 'another buildx verb' => [['docker', 'buildx', 'bake']];
        yield 'docker after another command' => [['timeout', '600', 'env', 'A=1', 'docker', 'compose', 'logs']];
        yield 'compose run by su' => [['su', '-s', '/bin/bash', 'alice', '-c', "'docker' 'compose' 'ps'"]];
        yield 'a command that is not docker' => [['git', 'status']];
        yield 'nothing' => [[]];
    }

    /** @param  list<string>  $cmd */
    #[DataProvider('otherCommands')]
    public function test_any_other_command_is_no_run(array $cmd): void
    {
        $this->assertNull(Privileged::accountCompose('alice')->forCommand($cmd));
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refused(): iterable
    {
        yield 'username that is a path' => [fn () => Privileged::accountCompose('../bob')->run(['compose', 'ps'])];
        yield 'a client it does not run' => [fn () => Privileged::accountCompose('alice')->run(['ps'])];
        yield 'buildx that is no build' => [fn () => Privileged::accountCompose('alice')->run(['buildx', 'bake'])];
        yield 'relative workdir' => [fn () => Privileged::accountCompose('alice')->run(['compose', 'ps'], 'project')];
        yield 'environment name with a space' => [fn () => Privileged::accountCompose('alice')->run(['compose', 'ps'], '', ['A B' => 'x'])];
        yield 'NUL in the command' => [fn () => Privileged::accountCompose('alice')->run(['compose', "a\0b"])];
    }

    /** @param  \Closure(): mixed  $build */
    #[DataProvider('refused')]
    public function test_a_value_the_broker_would_refuse_is_refused_before_it_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }
}
