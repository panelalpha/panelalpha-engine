<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\StaticBuildOutput;
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
 * jaeger-ui built packages/jaeger-ui/build and dashi-taskboard dist/web while the recipe served `dist`; the deploy
 * failed with "Static build finished but dist/index.html is missing" over a page the build had written.
 */
class HostCompileStaticOutputTest extends TestCase
{
    private const STARTED = 1_800_000_000;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-static-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    public function test_a_workspace_members_output_is_served_from_the_predicted_directory(): void
    {
        $this->write('packages/jaeger-ui/index.html', self::STARTED - 60, 'template');
        $this->write('packages/jaeger-ui/build/index.html', self::STARTED + 30, 'built');
        $this->write('packages/jaeger-ui/build/assets/app.js', self::STARTED + 30);

        $commands = $this->invoke('assertBuildProduced', $this->dir, 'dist', false);

        $this->assertStringEqualsFile($this->dir.'/dist/index.html', 'built');
        $this->assertFileExists($this->dir.'/dist/assets/app.js');
        $this->assertFileExists(StaticBuildOutput::markerIn($this->dir.'/dist'));
        $this->assertDirectoryDoesNotExist($this->dir.'/packages/jaeger-ui/build');
        $this->assertDirectoryDoesNotExist($this->dir.'/.panelalpha-static-output');
        $this->assertStringEqualsFile($this->dir.'/packages/jaeger-ui/index.html', 'template');
        $this->assertContains(
            Privileged::accountFiles(posix_getuid(), posix_getgid())->touch(StaticBuildOutput::markerIn($this->dir.'/dist')),
            $commands
        );
    }

    public function test_an_output_inside_the_predicted_directory_replaces_it(): void
    {
        $this->write('dist/cli.js', self::STARTED + 10);
        $this->write('dist/web/index.html', self::STARTED + 30, 'built');

        $this->invoke('assertBuildProduced', $this->dir, 'dist', false);

        $this->assertStringEqualsFile($this->dir.'/dist/index.html', 'built');
        $this->assertFileDoesNotExist($this->dir.'/dist/cli.js');
    }

    public function test_an_output_around_a_nested_prediction_is_moved_into_it(): void
    {
        $this->write('build/index.html', self::STARTED + 30, 'built');

        $this->invoke('assertBuildProduced', $this->dir, 'build/client', false);

        $this->assertStringEqualsFile($this->dir.'/build/client/index.html', 'built');
    }

    public function test_a_predicted_index_is_served_as_it_is(): void
    {
        $this->write('dist/index.html', self::STARTED + 30, 'built');
        $this->write('other/index.html', self::STARTED + 30, 'other');

        $this->invoke('assertBuildProduced', $this->dir, 'dist', false);

        $this->assertFileDoesNotExist(StaticBuildOutput::markerIn($this->dir.'/dist'));
        $this->assertFileExists($this->dir.'/other/index.html');
    }

    public function test_several_new_pages_are_named_rather_than_guessed(): void
    {
        $this->write('apps/a/dist/index.html', self::STARTED + 30);
        $this->write('apps/b/dist/index.html', self::STARTED + 30);

        try {
            $this->invoke('assertBuildProduced', $this->dir, 'dist', false);
            $this->fail('expected the missing-index error');
        } catch (\Exception $e) {
            $this->assertSame(
                'Static build finished but dist/index.html is missing; the build wrote index.html in several directories: apps/a/dist, apps/b/dist',
                $e->getMessage()
            );
        }
        $this->assertDirectoryDoesNotExist($this->dir.'/dist');
    }

    public function test_only_old_pages_fail_as_before(): void
    {
        $this->write('web/index.html', self::STARTED - 60);
        $this->write('public/index.html', self::STARTED + 30);

        $this->expectExceptionMessage('Static build finished but dist/index.html is missing');

        try {
            $this->invoke('assertBuildProduced', $this->dir, 'dist', false);
        } finally {
            $this->assertDirectoryDoesNotExist($this->dir.'/dist');
        }
    }

    public function test_the_checkout_itself_is_never_replaced(): void
    {
        $this->write('site/index.html', self::STARTED + 30);

        $this->expectExceptionMessage('Static build finished but ./index.html is missing');

        $this->invoke('assertBuildProduced', $this->dir, '.', false);
    }

    /** A rebuild writes packages/jaeger-ui/build again; the copy moved last time must not answer instead. */
    public function test_only_a_relocated_output_is_cleared_before_the_build(): void
    {
        $this->write('dist/index.html', self::STARTED - 60);
        $this->invoke('clearRelocatedStaticOutput', $this->dir, 'dist');
        $this->assertFileExists($this->dir.'/dist/index.html');

        $this->write('dist/'.StaticBuildOutput::RELOCATED_MARKER, self::STARTED - 60);
        $this->invoke('clearRelocatedStaticOutput', $this->dir, 'dist');
        $this->assertDirectoryDoesNotExist($this->dir.'/dist');
    }

    private function write(string $relative, int $mtime, string $contents = 'x'): void
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        touch($path, $mtime);
    }

    /** @return list<list<string>> */
    private function invoke(string $method, mixed ...$args): array
    {
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
        $user->method('linuxIds')->willReturn([posix_getuid(), posix_getgid()]);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionProperty($compile, 'buildStartedAt'))->setValue($compile, self::STARTED);
        (new \ReflectionMethod($compile, $method))->invoke($compile, ...$args);

        return $system->commands;
    }
}
