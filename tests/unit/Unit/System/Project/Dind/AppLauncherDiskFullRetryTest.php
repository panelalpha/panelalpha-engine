<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The ENOSPC retry reclaims with `docker system prune -af`, which takes every
 * base image no container holds. Retried straight away, compose asked Docker
 * Hub for panelalpha/php, which is on no registry: the failure the pre-build
 * reclaim order was fixed for. A booted app, because the retry records a
 * Telemetry signal.
 */
class AppLauncherDiskFullRetryTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    public function test_the_images_are_preloaded_again_before_the_retry(): void
    {
        $launcher = $this->launcher();
        $failed = new Process(['sh', '-c', 'echo "write /var/lib/docker/x: no space left on device" >&2; exit 1']);
        $failed->run();

        $this->retry($launcher, $failed, 'node:22-bookworm-slim', 'dockerfile', null);

        $this->assertSame(
            ['reclaimStorage', 'ensureImage', 'preloadFrameworkBaseImages', 'preloadComposeImages', 'compose up'],
            $this->calls
        );
    }

    public function test_the_host_compile_is_not_run_again(): void
    {
        $launcher = $this->launcher();
        $failed = new Process(['sh', '-c', 'echo "ENOSPC: no space left on device" >&2; exit 1']);
        $failed->run();

        $this->retry($launcher, $failed, null, 'vite', 'nginx');

        $this->assertSame(['reclaimStorage', 'ensureImage', 'preloadComposeImages', 'compose up'], $this->calls);
    }

    public function test_a_build_that_did_not_fill_the_disk_is_not_retried(): void
    {
        $launcher = $this->launcher();
        $failed = new Process(['sh', '-c', 'echo "exit code: 2" >&2; exit 1']);
        $failed->run();

        $this->retry($launcher, $failed, null, 'dockerfile', null);

        $this->assertSame([], $this->calls);
    }

    /** The closure start() hands over, for this strategy and runtime. */
    private function retry(AppLauncher $launcher, Process $failed, ?string $image, string $strategy, ?string $runtime): void
    {
        (new \ReflectionMethod(AppLauncher::class, 'retryOnceIfDiskFull'))->invoke(
            $launcher,
            $failed,
            ['up', '-d'],
            fn () => (new \ReflectionMethod(AppLauncher::class, 'preloadImages'))
                ->invoke($launcher, $strategy, $runtime, $runtime === 'nginx', $image, true)
        );
    }

    private function launcher(): AppLauncher
    {
        $calls = &$this->calls;
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('canAggressivelyReclaim')->willReturn(true);
        foreach (['reclaimStorage', 'reclaimStorageIfNeeded', 'ensureImage', 'preloadFrameworkBaseImages', 'preloadComposeImages'] as $method) {
            $inner->method($method)->willReturnCallback(function () use (&$calls, $method): void {
                $calls[] = $method;
            });
        }

        $system = new class ($calls) extends System {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->calls[] = 'compose up';
                $process = new Process(['true']);
                $process->run();

                return $process;
            }
        };

        $user = new User();
        $user->username = 'enospc-' . bin2hex(random_bytes(4));

        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($inner);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn($user->username);
        $project->method('userModel')->willReturn($user);
        $project->method('composeFilePath')->willReturn('/users/acme/docker-compose.yml');
        $project->method('userAppComposeFileToRun')->willReturn('/home/acme/project/docker-compose.panelalpha.yml');
        $project->method('hostCompile')->willReturnCallback(function () use (&$calls): never {
            $calls[] = 'hostCompile';
            throw new \LogicException('host compile run again');
        });
        $project->method('shell')->willReturn(new ShellOperations($project));

        return new AppLauncher($project);
    }
}
