<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * A `docker compose` or `docker buildx build` line runs from the broker only with docker first, after an optional
 * `env NAME=value ...`; any other shape is refused, since it would reach the daemon around the broker.
 */
class ShellDockerClientLineTest extends TestCase
{
    /** @var list<list<string>> */
    private array $commands = [];

    /** @return iterable<string, array{list<string>}> */
    public static function brokerLines(): iterable
    {
        yield 'compose' => [['docker', 'compose', 'ps']];
        yield 'compose after env' => [['env', 'PWD=/home/alice/project', 'docker', 'compose', 'up', '-d']];
        yield 'buildx build' => [['docker', 'buildx', 'build', '.']];
    }

    /** @param  list<string>  $cmd */
    #[DataProvider('brokerLines')]
    public function test_a_client_line_with_docker_first_runs_from_the_broker(array $cmd): void
    {
        $shell = new ShellOperations($this->project());

        $this->assertSame('account-compose.run', LocalRoot::action($shell->wrap($cmd)));
        $this->assertSame('account-compose.run', LocalRoot::action($shell->wrap($cmd, true)));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function containerLines(): iterable
    {
        yield 'another docker verb' => [['docker', 'ps']];
        yield 'a command that is not docker' => [['git', 'status']];
        yield 'a shell\'s own text' => [['sh', '-c', 'docker compose ps']];
    }

    /** @param  list<string>  $cmd */
    #[DataProvider('containerLines')]
    public function test_a_line_running_neither_client_runs_in_the_account_container(array $cmd): void
    {
        $shell = new ShellOperations($this->project());

        $this->assertSame('account-container.exec', LocalRoot::action($shell->wrap($cmd)));
        $this->assertSame('account-container.exec', LocalRoot::action($shell->wrap($cmd, true)));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function refusedLines(): iterable
    {
        yield 'compose behind timeout' => [['timeout', '60', 'docker', 'compose', 'up']];
        yield 'buildx behind nice' => [['nice', 'docker', 'buildx', 'build', '.']];
        yield 'compose behind another command after env' => [['env', 'A=1', 'timeout', '5', 'docker', 'compose', 'ps']];
        yield 'compose behind an env option' => [['env', '-i', 'docker', 'compose', 'ps']];
        yield 'docker by its path' => [['/usr/bin/docker', 'compose', 'ps']];
        yield 'a buildx verb the broker does not run' => [['docker', 'buildx', 'bake']];
    }

    /** @param  list<string>  $cmd */
    #[DataProvider('refusedLines')]
    public function test_a_line_running_a_client_any_other_way_is_refused_as_root(array $cmd): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Refusing to run '.json_encode(implode(' ', $cmd), JSON_UNESCAPED_SLASHES)
            .' in the account\'s container: a docker compose or docker buildx build line runs from the root broker,'
            .' with docker first, after an optional env NAME=value ...'));

        (new ShellOperations($this->project()))->wrap($cmd);
    }

    /** @param  list<string>  $cmd */
    #[DataProvider('refusedLines')]
    public function test_a_line_running_a_client_any_other_way_is_refused_as_the_account(array $cmd): void
    {
        $shell = new ShellOperations($this->project());

        foreach ([fn () => $shell->wrap($cmd, true), fn () => $shell->execAsUserQuiet($cmd)] as $run) {
            try {
                $run();
                $this->fail('ran '.implode(' ', $cmd));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('Refusing to run ', $e->getMessage());
            }
        }
        $this->assertSame([], $this->commands);
    }

    private function project(): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $this->commands[] = (array) $cmd;

            return '';
        });
        $user = $this->createStub(User::class);
        $user->method('getChownString')->willReturn('1000:1000');

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn('alice');
        $project->method('userModel')->willReturn($user);
        $project->method('homeDirPath')->willReturn('/home/alice/');

        return $project;
    }
}
