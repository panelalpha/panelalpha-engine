<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * The stack lookups name the stack as compose run from the broker names it: with the environment the broker's
 * client gets over the env files, and not at all where compose refuses the name.
 */
class StackProjectNameTest extends TestCase
{
    private const APP = '/home/alice/project';

    /** @var array<string, string> */
    private array $files = [];

    public function test_the_brokers_client_environment_wins_over_the_env_files(): void
    {
        $this->files = [
            self::APP.'/'.EngineArtifacts::RUN_COMPOSE => 'name: ${USER}-${APP}-${DOCKER_CONFIG}',
            self::APP.'/.env' => "USER=bob\nAPP=shop\nDOCKER_CONFIG=/elsewhere\n",
        ];

        $this->assertSame('alice-shop-homealicepanelalpha-docker', (new Paths($this->project()))->composeProjectName());
    }

    public function test_pwd_is_the_project_the_rest_what_the_broker_sets(): void
    {
        $this->files = [self::APP.'/'.EngineArtifacts::RUN_COMPOSE => 'name: ${HOME}${PWD}${LOGNAME}${PATH}${DOCKER_HOST}'];

        $this->assertSame('homealicehomealiceprojectaliceusrbinunixrundockersock', (new Paths($this->project()))->composeProjectName());
    }

    public function test_a_name_compose_refuses_is_refused_for_the_lookups(): void
    {
        $this->files = [
            self::APP.'/'.EngineArtifacts::RUN_COMPOSE => "services: {}\n",
            self::APP.'/.env' => "COMPOSE_PROJECT_NAME=My_App\n",
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid project name "My_App"');

        (new Paths($this->project()))->composeProjectName();
    }

    private function project(): Dind
    {
        $files = &$this->files;
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileExists')->willReturnCallback(static fn (string $path): bool => isset($files[$path]));
        $filesystem->method('fileGetContents')->willReturnCallback(static fn (string $path): string => $files[$path]);
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $user = $this->createStub(User::class);
        $user->method('getDeployStrategy')->willReturn('nodejs');

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn('alice');
        $project->method('userModel')->willReturn($user);
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }
}
