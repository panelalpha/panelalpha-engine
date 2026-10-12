<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A Procfile release runs before `up`, while a rebuild's old version is still
 * serving. A failed one must stop there and say so, so the redeploy does not
 * tear down an app it never replaced.
 */
class AppLauncherReleaseTest extends TestCase
{
    /** @var list<string> compose subcommand per process run, in order */
    private array $ran = [];

    /** What `compose ps` answers before the release; null makes it fail. */
    private ?string $ps = "0123abcd\n";

    /** What `compose build` runs as. */
    private string $build = 'exit 0';

    /** The app's compose file; the release is added to it. */
    private ?string $compose = null;

    /** Whether the inner Docker reports it can reclaim; the ENOSPC retry needs it. */
    private bool $canReclaim = false;

    /** @var list<string> the inner Docker calls the retry made */
    private array $reclaimed = [];

    /** Runs when the launcher asks what is running, the last step before the release. */
    private ?\Closure $onPs = null;

    public function test_a_failed_release_stops_before_up_and_is_flagged(): void
    {
        $result = $this->launcher('echo migrate failed; exit 3')->start();

        $this->assertSame(['build release', 'run --rm -T release'], $this->ran);
        $this->assertSame(3, $result['exit_code']);
        $this->assertTrue($result[AppLauncher::RELEASE_FAILED] ?? false);
        $this->assertStringStartsWith(
            'The Procfile release process exited with code 3; the new version was not started.',
            $result['stderr']
        );
        $this->assertStringContainsString('migrate failed', $result['stdout']);
        $this->assertTrue($result[AppLauncher::RAN_BEFORE_RELEASE]);
    }

    /** A first deploy had nothing running, so nothing was "left as it was". */
    public function test_a_failed_release_says_whether_anything_was_running_before_it(): void
    {
        $this->ps = '';
        $result = $this->launcher('exit 3')->start();
        $this->assertTrue($result[AppLauncher::RELEASE_FAILED]);
        $this->assertFalse($result[AppLauncher::RAN_BEFORE_RELEASE]);

        $this->ps = null;
        $result = $this->launcher('exit 3')->start();
        $this->assertNull($result[AppLauncher::RAN_BEFORE_RELEASE], 'could not ask: unknown, not "nothing"');
    }

    public function test_a_release_that_succeeds_is_followed_by_up(): void
    {
        $result = $this->launcher('exit 0')->start();

        $this->assertSame(['build release', 'run --rm -T release'], array_slice($this->ran, 0, 2));
        $this->assertStringStartsWith('up -d --remove-orphans', $this->ran[2] ?? '');
        $this->assertArrayNotHasKey(AppLauncher::RELEASE_FAILED, $result, 'a failed up is not a failed release');
    }

    /** The image is built before the release runs: a build that fails is the build's failure. */
    public function test_a_failed_build_is_not_reported_as_the_release(): void
    {
        $this->build = 'echo "#3 ERROR: failed to solve: no build stage in current context"; '
            . 'echo "failed to solve: no build stage in current context" >&2; exit 17';
        $result = $this->launcher('echo never ran')->start();

        $this->assertSame(['build release'], $this->ran, 'neither the release nor up runs after a failed build');
        $this->assertSame(17, $result['exit_code']);
        $this->assertArrayNotHasKey(AppLauncher::RELEASE_FAILED, $result);
        $this->assertStringNotContainsString('Procfile release', $result['stderr']);
        $this->assertStringContainsString('failed to solve: no build stage in current context', $result['stderr']);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT] ?? false, 'a running version still serves');

        // A first deploy had nothing to keep.
        $this->ran = [];
        $this->ps = '';
        $result = $this->launcher('echo never ran')->start();
        $this->assertSame(17, $result['exit_code']);
        $this->assertArrayNotHasKey(ZeroDowntimeRedeploy::PREVIOUS_KEPT, $result);
        $this->assertArrayNotHasKey(AppLauncher::RELEASE_FAILED, $result);
    }

    /**
     * A release-line app builds in `runRelease()`; a build that fills the
     * inner Docker store must be reclaimed and retried like the `up` and
     * beginSwap() builds, not failed as the release's.
     */
    public function test_a_release_build_that_hits_enospc_is_reclaimed_and_retried(): void
    {
        $this->build = 'echo "write /var/lib/docker/x: no space left on device" >&2; exit 1';
        $this->canReclaim = true;
        $result = $this->launcher('echo migrate ran')->start();

        $this->assertSame(
            ['build release', 'build release', 'run --rm -T release'],
            array_slice($this->ran, 0, 3),
            'the failed build is retried once before the release runs'
        );
        $this->assertSame(['reclaimStorage'], $this->reclaimed);
        $this->assertArrayNotHasKey(AppLauncher::RELEASE_FAILED, $result, 'the retry succeeded: not a failed release');
    }

    /** A release build that cannot reclaim fails as it did before, once. */
    public function test_a_release_build_that_cannot_reclaim_is_not_retried(): void
    {
        $this->build = 'echo "write /var/lib/docker/x: no space left on device" >&2; exit 1';
        $this->canReclaim = false;
        $this->launcher('echo never ran')->start();

        $this->assertSame(['build release'], $this->ran);
        $this->assertSame([], $this->reclaimed);
    }

    /** What the release starts and builds is built with it; image-only services are not. */
    public function test_the_build_covers_the_release_and_the_services_it_starts(): void
    {
        $this->compose = <<<'YAML'
            services:
              app:
                build: { context: . }
                depends_on: [db, assets]
              db:
                image: postgres:16
              assets:
                build: { context: ./assets }
                depends_on:
                  db: { condition: service_started }
            YAML;
        $this->launcher('exit 3')->start();
        $this->assertSame(['build release assets', 'run --rm -T release'], $this->ran);

        // An image-only app has nothing to build: the release runs straight away.
        $this->ran = [];
        $this->compose = DeployCompose::railpack('project-app', 3000);
        $this->launcher('exit 3')->start();
        $this->assertSame(['run --rm -T release'], $this->ran);
    }

    public function test_a_deploy_cancelled_before_the_release_does_not_start_it(): void
    {
        $this->compose = DeployCompose::railpack('project-app', 3000);
        $username = 'release-' . bin2hex(random_bytes(4));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $deploy = DeployLogger::start($username);
        $deploy->stage(DeployLogger::STAGE_RUNNING);
        $this->onPs = static fn () => DeployLogger::requestCancel($username);

        try {
            $this->launcher('echo migrate ran', $username)->start();
            $this->fail('the cancelled deploy went on');
        } catch (DeployCancelledException) {
        }

        $this->assertSame([], $this->ran, 'the release never started');
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    private function launcher(string $release, ?string $username = null): AppLauncher
    {
        $ran = &$this->ran;
        $reclaimed = &$this->reclaimed;
        $ps = $this->ps;
        // The ENOSPC scripts fail on their first build and succeed on the retry,
        // so a reclaimed-once build reaches the release.
        $enospc = str_contains($this->build, 'no space left on device');
        $build = $this->build;
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileGetContents')->willReturn(GeneratedCompose::withProcesses(
            $this->compose ?? DeployCompose::dockerfile('Dockerfile', 3000),
            ['release' => 'node migrate.js']
        ));
        $system = new class ($ran, $release, $build, $filesystem, $ps, $enospc, $this->onPs) extends System {
            private int $builds = 0;

            /** @param list<string> $ran */
            public function __construct(
                private array &$ran,
                private string $release,
                private string $build,
                private Filesystem $files,
                private ?string $ps,
                private bool $enospc,
                private ?\Closure $onPs,
            ) {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (!str_contains(implode(' ', (array) $cmd), "'ps' '--quiet' '--status' 'running'")) {
                    throw new \RuntimeException('unexpected command');
                }
                if ($this->onPs !== null) {
                    ($this->onPs)();
                }

                return $this->ps ?? throw new \RuntimeException('docker daemon not reachable');
            }

            public function filesystem(): Filesystem
            {
                return $this->files;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                // Only the compose commands the launcher runs itself (marked `--` below); the
                // zero-downtime check for a running version goes through the account's shell.
                $sub = in_array('--', $cmd, true) ? array_slice($cmd, (int) array_search('--', $cmd, true) + 1) : [];
                if ($sub !== []) {
                    $this->ran[] = implode(' ', $sub);
                }
                $script = match ($sub[0] ?? null) {
                    // An ENOSPC script fills the store on the first build and is
                    // reclaimed before the retry, which then succeeds.
                    'build' => $this->enospc && $this->builds++ > 0 ? 'exit 0' : $this->build,
                    'run' => $this->release,
                    default => 'echo "up stopped here" >&2; exit 1',
                };
                $process = new Process(['sh', '-c', $script]);
                $process->run();

                return $process;
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }
        };

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('canAggressivelyReclaim')->willReturn($this->canReclaim);
        $inner->method('reclaimStorage')->willReturnCallback(function () use (&$reclaimed): void {
            $reclaimed[] = 'reclaimStorage';
        });

        $user = new User();
        $user->username = $username ?? 'release-' . bin2hex(random_bytes(4));
        $user->setDetails(['deploy_strategy' => 'dockerfile']);

        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($inner);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn($user->username);
        $project->method('userModel')->willReturn($user);
        $project->method('composeFilePath')->willReturn('/users/acme/docker-compose.yml');
        $project->method('userAppComposeFileToRun')->willReturn('/home/acme/.panelalpha/docker-compose.yml');
        // The real command is `env ... docker compose ...`; `--` marks where the subcommand starts here.
        $project->method('userAppComposeCommand')->willReturnCallback(
            static fn (array $rest): array => ['docker', 'compose', '--', ...$rest]
        );
        $project->method('registryLogin')->willReturn(new RegistryLogin($project));
        $project->method('shell')->willReturn(new ShellOperations($project));

        return new AppLauncher($project);
    }
}
