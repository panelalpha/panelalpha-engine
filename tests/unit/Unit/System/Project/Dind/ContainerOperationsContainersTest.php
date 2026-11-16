<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/** The containers list of the API is the account daemon's stack, every container in the rows `compose ps --all` prints. */
final class ContainerOperationsContainersTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    public function test_the_list_is_every_stack_container_as_compose_prints_it_with_no_compose_run(): void
    {
        $containers = $this->operations([
            json_encode([
                ['Id' => str_repeat('a', 64), 'Names' => ['/shop-web-1'], 'Image' => 'nginx', 'State' => 'running', 'Status' => 'Up 5 minutes', 'Labels' => ['com.docker.compose.service' => 'web']],
                ['Id' => str_repeat('b', 64), 'Names' => ['/shop-migrate-run-1'], 'Image' => 'shop', 'State' => 'exited', 'Status' => 'Exited (0) 1 minute ago', 'Labels' => ['com.docker.compose.service' => 'migrate']],
            ]),
            json_encode([
                ['State' => ['Status' => 'exited', 'ExitCode' => 0]],
                ['State' => ['Status' => 'running'], 'NetworkSettings' => ['Ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8080']]]]],
            ]),
        ])->getContainers();

        $this->assertSame(
            [['migrate', 'shop', 'exited', 'Exited (0) 1 minute ago', ''], ['web', 'nginx', 'running', 'Up 5 minutes', '0.0.0.0:8080->80/tcp']],
            array_map(static fn (array $row): array => [$row['Service'], $row['Image'], $row['State'], $row['Status'], $row['Ports']], $containers),
        );
        $this->assertSame(['shop-migrate-run-1', 'shop-web-1'], array_column($containers, 'Name'));
        $this->assertSame(['account-docker.listContainers', 'account-docker.containers'], $this->asked);
    }

    public function test_the_list_takes_stopped_and_one_off_containers(): void
    {
        $this->operations(['[]'])->getContainers();

        $this->assertSame(['account-docker.listContainers'], $this->asked);
    }

    public function test_a_daemon_that_cannot_say_lists_nothing(): void
    {
        $this->assertSame([], $this->operations([new DockerErrorException('container alice is not running')])->getContainers());
        $this->assertSame([], $this->operations([json_encode([['Id' => str_repeat('a', 64), 'Names' => ['/shop-web-1']]]), '[null]'])->getContainers());
        $this->assertSame([], $this->operations(['no json'])->getContainers());
    }

    /** @param  list<string|\Throwable>  $answers */
    private function operations(array $answers): ContainerOperations
    {
        $this->asked = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (array $argv) use (&$answers): string {
            $this->asked[] = (string) LocalRoot::action($argv);
            if ($this->asked === ['account-docker.listContainers']) {
                $this->assertSame(['all', '2', 'label', 'com.docker.compose.project=shop', 'label', 'com.docker.compose.config-hash'], array_slice(LocalRoot::values($argv), 1));
            }
            $answer = array_shift($answers) ?? '';
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn('alice');
        $project->method('userAppComposeProjectName')->willReturn('shop');
        $project->method('system')->willReturn($system);
        $project->method('accountDaemon')->willReturnCallback(static fn () => new AccountDaemon($project));

        return new ContainerOperations($project, new ShellOperations($project));
    }
}
