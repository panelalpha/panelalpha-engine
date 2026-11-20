<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DiskLimitException;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\RailpackStrategy;
use Tests\Support\LocalRoot;
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

    private ?\RuntimeException $buildError = null;

    private bool $cleanupFails = false;

    /** @var list<list<string>> */
    private array $cleanups = [];

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

    /** A build the engine stopped fails the deploy rather than falling back, and still releases the guard. */
    public function test_a_build_the_disk_guard_stopped_is_not_a_fallback(): void
    {
        $this->buildError = new DiskLimitException('PANELALPHA: disk limit reached (3G free)');

        try {
            $this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest');
            $this->fail('the disk limit reaches the deploy');
        } catch (DiskLimitException) {
        }

        $this->assertSame(['which', 'prepare', 'build-guard', 'build', 'build-guard-release', 'rm'], $this->ran);
    }

    public function test_a_stalled_build_is_not_a_fallback(): void
    {
        $this->buildError = new BuildStalledException('the build printed nothing for 900s');

        $this->expectException(BuildStalledException::class);

        $this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest');
    }

    public function test_the_scratch_files_are_removed_as_root(): void
    {
        $this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest');

        $this->assertSame([
            Privileged::files()->removeIfExists('/home/alice/.panelalpha/railpack-build-tmp.sh', '/home/alice/.panelalpha/railpack-plan-tmp.json'),
        ], $this->cleanups);
    }

    public function test_a_cleanup_that_fails_keeps_a_built_image(): void
    {
        $this->cleanupFails = true;

        $this->assertTrue($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));
    }

    public function test_a_cleanup_that_fails_keeps_the_fallback_of_a_failed_build(): void
    {
        $this->buildFails = true;
        $this->cleanupFails = true;

        $this->assertFalse($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));
    }

    private function railpack(): RailpackStrategy
    {
        $files = $this->createStub(Filesystem::class);
        $files->method('fileGetContents')->willReturn('{}');

        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($files);
        $system->method('directoryExists')->willReturn(true);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = LocalRoot::plain(array_values((array) $cmd));
            $this->ran[] = $this->step($argv);
            if ($this->buildError !== null && end($this->ran) === 'build') {
                throw $this->buildError;
            }
            if ($this->buildFails && end($this->ran) === 'build') {
                throw new \RuntimeException('ERROR: failed to solve');
            }
            if (end($this->ran) === 'rm') {
                $this->cleanups[] = array_values((array) $cmd);
                if ($this->cleanupFails) {
                    throw new \RuntimeException('rm: cannot remove: Permission denied');
                }
            }

            return '';
        });

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder('8192m', ''));

        $user = new User();
        $user->username = 'alice';
        $user->setDetails(['UID' => 1001, 'GID' => 1001]);

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('engine')->willReturn($engine);
        $project->method('userModel')->willReturn($user);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('composeFilePath')->willReturn('/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml');
        $project->method('innerDocker')->willReturn($this->createStub(InnerDocker::class));
        $project->method('registryLogin')->willReturn(new RegistryLogin($project));
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));

        return new RailpackStrategy($project);
    }

    /** @param  list<string>  $argv */
    private function step(array $argv): string
    {
        $guard = array_values(preg_grep('/^build-guard/', $argv) ?: []);
        if ($guard !== []) {
            return $guard[0];
        }
        if (array_slice($argv, 0, 2) === ['rm', '-f']) {
            return 'rm';
        }
        if (array_slice(LocalRoot::composeCommand($argv), 0, 3) === ['docker', 'buildx', 'build']) {
            return 'build';
        }

        return str_contains((string) end($argv), "'which' 'railpack'") ? 'which' : 'prepare';
    }
}
