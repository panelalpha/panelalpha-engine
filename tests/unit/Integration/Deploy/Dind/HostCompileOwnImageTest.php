<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * A host build runs its image by name on the host's docker, so the host gets
 * one of ours first; when it cannot, the deploy fails naming that base.
 */
class HostCompileOwnImageTest extends TestCase
{
    private const OURS = 'panelalpha/python:3.12-slim-pa2a0d75ad';

    /** @var list<string> images ensureOnHost() was asked for */
    private array $ensured = [];

    /** @var list<string> images a host build container ran */
    private array $containers = [];

    /**
     * Run host build containers in $images, in order, with ensureOnHost() answering $hostGetsIt.
     *
     * @param  list<string>  $images
     * @return array{containers: list<string>, pulls: list<string>}
     */
    private function build(array $images, bool $hostGetsIt): array
    {
        $system = new class extends System
        {
            /** @var list<string> */
            public array $containers = [];

            /** @var list<string> */
            public array $pulls = [];

            public function __construct() {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && LocalRoot::action($cmd) === 'host.refreshPublicImage') {
                    $this->pulls[] = (string) (LocalRoot::values($cmd)[0] ?? '');

                    return $this->exit(0);
                }
                $this->containers[] = LocalRoot::build(is_array($cmd) ? $cmd : [$cmd])['image'];

                return $this->exit(0);
            }

            private function exit(int $code): Process
            {
                $process = new Process(['php', '-r', "exit({$code});"]);
                $process->run();

                return $process;
            }
        };

        $model = new User;
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $bases = $this->createStub(SharedBaseImages::class);
        $bases->method('ensureOnHost')->willReturnCallback(function (string $image) use ($hostGetsIt): bool {
            $this->ensured[] = $image;

            return $hostGetsIt;
        });
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn(new DindImageStore);
        $inner->method('bases')->willReturn($bases);
        $dind->method('innerDocker')->willReturn($inner);

        $compile = new HostCompile($dind);
        $runContainer = new \ReflectionMethod($compile, 'runContainer');
        try {
            foreach ($images as $image) {
                $runContainer->invoke($compile, '/home/acme/project', $image, 'pip install -r requirements.txt', '', [], false, false);
            }
        } finally {
            $this->containers = $system->containers;
        }

        return ['containers' => $system->containers, 'pulls' => $system->pulls];
    }

    /** Ours is never pulled from docker.io: nothing publishes it there. */
    public function test_one_of_ours_is_put_on_the_host_before_the_build_runs_it(): void
    {
        $run = $this->build([self::OURS], true);

        $this->assertSame([self::OURS], $this->ensured);
        $this->assertSame([self::OURS], $run['containers']);
        $this->assertSame([], $run['pulls']);
    }

    public function test_one_the_host_cannot_get_fails_the_build_naming_the_base(): void
    {
        $failure = '';
        try {
            $this->build([self::OURS], false);
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        $this->assertStringStartsWith('The shared base image '.self::OURS.' could not be built or loaded on this server', $failure);
        $this->assertStringContainsString('the stock python:3.12-slim lacks what is baked into it', $failure);
        $this->assertSame([], $this->containers);
    }

    public function test_it_is_provided_once_per_deploy(): void
    {
        $run = $this->build([self::OURS, self::OURS], true);

        $this->assertSame([self::OURS], $this->ensured);
        $this->assertSame([self::OURS, self::OURS], $run['containers']);
    }

    public function test_a_public_image_is_refreshed_and_never_asked_of_the_cache_registry(): void
    {
        $run = $this->build(['node:22-bookworm-slim'], true);

        $this->assertSame([], $this->ensured);
        $this->assertSame(['node:22-bookworm-slim'], $run['containers']);
        $this->assertSame(['node:22-bookworm-slim'], $run['pulls']);
    }
}
