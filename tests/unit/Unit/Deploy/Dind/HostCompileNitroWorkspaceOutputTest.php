<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * wemux keeps its Vite root in apps/web, so its Nitro build wrote
 * apps/web/.output and the deploy failed with "no server entry was produced".
 */
class HostCompileNitroWorkspaceOutputTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-nitro-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    public function test_a_workspace_apps_nitro_output_is_moved_to_the_project_root(): void
    {
        $this->write('apps/web/.output/server/index.mjs');
        $this->write('apps/web/.output/public/index.html');

        $this->invoke('assertBuildProduced', $this->dir, '.output', true);

        $this->assertFileExists($this->dir . '/.output/server/index.mjs');
        $this->assertFileExists($this->dir . '/.output/public/index.html');
        $this->assertFileExists($this->dir . '/.output/' . StandaloneNodeServe::RELOCATED_MARKER);
        $this->assertDirectoryDoesNotExist($this->dir . '/apps/web/.output');
    }

    public function test_a_root_output_is_used_as_it_is(): void
    {
        $this->write('.output/server/index.mjs');
        $this->write('apps/web/.output/server/index.mjs');

        $this->invoke('assertBuildProduced', $this->dir, '.output', true);

        $this->assertFileDoesNotExist($this->dir . '/.output/' . StandaloneNodeServe::RELOCATED_MARKER);
        $this->assertFileExists($this->dir . '/apps/web/.output/server/index.mjs');
    }

    public function test_two_workspace_outputs_are_named_rather_than_guessed(): void
    {
        $this->write('apps/a/.output/server/index.mjs');
        $this->write('apps/b/.output/server/index.mjs');

        try {
            $this->invoke('assertBuildProduced', $this->dir, '.output', true);
            $this->fail('expected the missing-entry error');
        } catch (\Exception $e) {
            $this->assertStringContainsString('apps/a/.output, apps/b/.output', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->dir . '/.output');
    }

    /** The next deploy builds apps/web/.output again; the copy moved up last time must not answer instead. */
    public function test_only_a_relocated_output_is_cleared_before_the_build(): void
    {
        $this->write('.output/server/index.mjs');
        $this->invoke('clearRelocatedNitroOutput', $this->dir);
        $this->assertFileExists($this->dir . '/.output/server/index.mjs');

        $this->write('.output/' . StandaloneNodeServe::RELOCATED_MARKER);
        $this->invoke('clearRelocatedNitroOutput', $this->dir);
        $this->assertDirectoryDoesNotExist($this->dir . '/.output');
    }

    private function write(string $relative): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'x');
    }

    private function invoke(string $method, mixed ...$args): void
    {
        // Every command runs here, as ourselves.
        $system = new class () extends System {
            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(array_values(array_diff((array) $cmd, ['sudo'])));
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->runProcess($cmd)->getOutput();
            }
        };
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionMethod($compile, $method))->invoke($compile, ...$args);
    }
}
