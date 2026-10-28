<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\RailpackStrategy;
use Tests\TestCase;

/**
 * Railpack's `docker buildx build` runs in the account's own BuildKit, so it
 * is held like any other image build there: the build, and only the build.
 */
class RailpackBuildGuardTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    private bool $buildFails = false;

    public function test_the_buildx_build_runs_inside_the_guard(): void
    {
        $this->assertTrue($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));

        $this->assertSame(['which', 'prepare', 'build-guard', 'build', 'build-guard-release', 'rm'], $this->ran);
    }

    public function test_a_failed_build_still_releases_the_guard(): void
    {
        $this->buildFails = true;

        $this->assertFalse($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));

        $this->assertSame(['which', 'prepare', 'build-guard', 'build', 'build-guard-release', 'rm'], $this->ran);
    }

    private function railpack(): RailpackStrategy
    {
        $scripts = [];
        $files = $this->createStub(Filesystem::class);
        $files->method('writeFileReplacingPath')->willReturnCallback(
            function (string $path, string $contents) use (&$scripts): void {
                $scripts[$path] = $contents;
            }
        );
        $files->method('fileGetContents')->willReturn('{}');

        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($files);
        $system->method('directoryExists')->willReturn(true);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$scripts): string {
            $argv = array_values((array) $cmd);
            $this->ran[] = $this->step($argv, $scripts);
            if ($this->buildFails && end($this->ran) === 'build') {
                throw new \RuntimeException('ERROR: failed to solve');
            }

            return '';
        });

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder('8192m', ''));

        $user = new User();
        $user->username = 'alice';

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('engine')->willReturn($engine);
        $project->method('userModel')->willReturn($user);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('composeFilePath')->willReturn('/opt/users/alice/docker-compose.yml');
        $project->method('innerDocker')->willReturn($this->createStub(InnerDocker::class));
        $project->method('registryLogin')->willReturn(new RegistryLogin($project));
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));

        return new RailpackStrategy($project);
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $scripts  what each script path held when it ran
     */
    private function step(array $argv, array $scripts): string
    {
        $guard = array_values(preg_grep('/^build-guard/', $argv) ?: []);
        if ($guard !== []) {
            return $guard[0];
        }
        if (array_slice($argv, 0, 3) === ['sudo', 'rm', '-f']) {
            return 'rm';
        }
        $line = (string) end($argv);
        if (str_contains($line, "'which' 'railpack'")) {
            return 'which';
        }
        $script = $scripts[array_key_last($scripts)] ?? '';

        return str_contains($script, 'docker buildx build') ? 'build' : 'prepare';
    }
}
