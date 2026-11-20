<?php

namespace Tests\Support;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;

trait HostCompileRustLibrariesFixtures
{
    /**
     * @param  list<int>  $exitCodes  one per container, in order; 0 once they run out
     * @return list<array{image: string, script: string}>
     */
    private function bundle(string $compileImage, string $runtimeImage, bool $missing, array $exitCodes = []): array
    {
        $system = new class($missing, $exitCodes) extends System
        {
            /** @var list<array{image: string, script: string}> */
            public array $containers = [];

            /** @var list<string> */
            public array $pulls = [];

            /** @param list<int> $exitCodes */
            public function __construct(private bool $missing, private array $exitCodes) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                // The host re-pulls a public image before its first container; not a container itself.
                if (is_array($cmd) && LocalRoot::action($cmd) === 'host.refreshPublicImage') {
                    $this->pulls[] = (string) (LocalRoot::values($cmd)[0] ?? '');

                    return $this->exit(0);
                }
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $plain = LocalRoot::plain($argv);
                if (($plain[0] ?? null) === 'test') {
                    $found = $this->missing && end($plain) === '/home/acme/project/'.RustRuntimeLibraries::MISSING_FILE;

                    return $this->exit($found ? 0 : 1);
                }
                $build = LocalRoot::build($argv);
                // Without the OOM report every host build script starts with.
                $script = (string) preg_replace("/^trap '[^']*' EXIT; /", '', $build['script']);
                $this->containers[] = ['image' => $build['image'], 'script' => $script];

                return $this->exit(array_shift($this->exitCodes) ?? 0);
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
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn(new DindImageStore);
        $dind->method('innerDocker')->willReturn($inner);

        $compile = new HostCompile($dind);
        (new \ReflectionMethod($compile, 'bundleRustRuntimeLibraries'))
            ->invoke($compile, '/home/acme/project', $compileImage, $runtimeImage);

        return $system->containers;
    }
}
