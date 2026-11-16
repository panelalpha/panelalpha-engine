<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Models\User;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

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

        $commands = $this->invoke('assertBuildProduced', $this->dir, '.output', true);

        $this->assertFileExists($this->dir . '/.output/server/index.mjs');
        $this->assertFileExists($this->dir . '/.output/public/index.html');
        $this->assertFileExists($this->dir . '/.output/' . StandaloneNodeServe::RELOCATED_MARKER);
        $this->assertDirectoryDoesNotExist($this->dir . '/apps/web/.output');
        $owner = getmyuid().':'.getmygid();
        $remove = Privileged::files()->removeTree($this->dir.'/.output');
        $this->assertSame([
            $remove,
            Privileged::files()->rename($this->dir.'/apps/web/.output', $this->dir.'/.output'),
            Privileged::files()->changeOwnerRecursively($owner, $this->dir.'/.output'),
            Privileged::accountFiles(getmyuid(), getmygid())->touch($this->dir.'/.output/'.StandaloneNodeServe::RELOCATED_MARKER),
        ], array_slice($commands, (int) array_search($remove, $commands, true), 4));
    }

    public function test_the_relocated_marker_is_touched_as_the_account(): void
    {
        $this->write('apps/web/.output/server/index.mjs');

        $commands = $this->invoke('assertBuildProduced', $this->dir, '.output', true);

        $touch = array_values(array_filter($commands, static fn (array $argv): bool => str_ends_with((string) LocalRoot::action($argv), '.touch')));
        $this->assertCount(1, $touch);
        $this->assertSame('account.touch', LocalRoot::action($touch[0]));
        $this->assertNotContains(Privileged::files()->touch($this->dir.'/.output/'.StandaloneNodeServe::RELOCATED_MARKER), $commands);
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

    /** @return list<list<string>> */
    private function invoke(string $method, mixed ...$args): array
    {
        // Every command runs here, as ourselves.
        $system = new class () extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;
                $process = new Process(LocalRoot::argv($argv));
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->runProcess($cmd)->getOutput();
            }
        };
        $user = $this->createStub(User::class);
        $user->method('linuxIds')->willReturn([getmyuid(), getmygid()]);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionMethod($compile, $method))->invoke($compile, ...$args);

        return $system->commands;
    }
}
