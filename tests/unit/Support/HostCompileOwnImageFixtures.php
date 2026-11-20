<?php

namespace Tests\Support;

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
use Symfony\Component\Process\Process;

trait HostCompileOwnImageFixtures
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
}
