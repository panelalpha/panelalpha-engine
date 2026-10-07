<?php

namespace Tests\Unit\Deploy\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * After a Rust host compile, the binary is checked in the image it will run
 * in. Only a gap costs the build-image copy and the second check; a
 * binary slim can run gets one container and nothing else.
 */
class HostCompileRustLibrariesTest extends TestCase
{
    /**
     * @param list<int> $exitCodes one per container, in order; 0 once they run out
     * @return list<array{image: string, script: string}>
     */
    private function bundle(string $compileImage, string $runtimeImage, bool $missing, array $exitCodes = []): array
    {
        $system = new class ($missing, $exitCodes) extends System {
            /** @var list<array{image: string, script: string}> */
            public array $containers = [];

            /** @param list<int> $exitCodes */
            public function __construct(private bool $missing, private array $exitCodes)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = is_array($cmd) ? $cmd : [$cmd];
                if (($cmd[1] ?? null) === 'test') {
                    $found = $this->missing && end($cmd) === '/home/acme/project/' . RustRuntimeLibraries::MISSING_FILE;

                    return $this->exit($found ? 0 : 1);
                }
                $sh = array_search('sh', $cmd, true);
                // Without the OOM report every host build script starts with.
                $script = (string) preg_replace("/^trap '[^']*' EXIT; /", '', (string) end($cmd));
                $this->containers[] = ['image' => (string) $cmd[$sh - 1], 'script' => $script];

                return $this->exit(array_shift($this->exitCodes) ?? 0);
            }

            private function exit(int $code): Process
            {
                $process = new Process(['php', '-r', "exit({$code});"]);
                $process->run();

                return $process;
            }
        };

        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder());
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionMethod($compile, 'bundleRustRuntimeLibraries'))
            ->invoke($compile, '/home/acme/project', $compileImage, $runtimeImage);

        return $system->containers;
    }

    public function test_a_binary_the_runtime_image_can_run_costs_one_check_and_nothing_else(): void
    {
        $this->assertSame(
            [['image' => 'rust:1-slim-bookworm', 'script' => RustRuntimeLibraries::checkScript()]],
            $this->bundle('rust:1-bookworm', 'rust:1-slim-bookworm', false)
        );
    }

    public function test_a_gap_is_filled_from_the_build_image_and_checked_again_in_the_runtime(): void
    {
        $this->assertSame(
            [
                ['image' => 'rust:1-slim-bookworm', 'script' => RustRuntimeLibraries::checkScript()],
                ['image' => 'rust:1-bookworm', 'script' => RustRuntimeLibraries::bundleScript()],
                ['image' => 'rust:1-slim-bookworm', 'script' => RustRuntimeLibraries::verifyScript()],
            ],
            $this->bundle('rust:1-bookworm', 'rust:1-slim-bookworm', true)
        );
    }

    /** A manifest that pinned one image compiles and runs in it: nothing to compare. */
    public function test_one_image_for_both_is_not_checked(): void
    {
        $this->assertSame([], $this->bundle('ghcr.io/acme/rust:1', 'ghcr.io/acme/rust:1', true));
        $this->assertSame([], $this->bundle('rust:1-bookworm', '', true));
    }

    /** A runtime image the check cannot run in keeps the behaviour it had. */
    public function test_a_check_that_cannot_run_does_not_fail_the_deploy(): void
    {
        $containers = $this->bundle('rust:1-bookworm', 'gcr.io/distroless/cc-debian12:latest', true, [125]);

        $this->assertCount(1, $containers);
    }

    public function test_a_library_still_missing_after_bundling_fails_the_deploy(): void
    {
        $this->expectException(\Exception::class);

        $this->bundle('rust:1-bookworm', 'rust:1-slim-bookworm', true, [0, 0, 1]);
    }
}
