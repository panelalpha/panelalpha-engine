<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
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

    private function launcher(string $release): AppLauncher
    {
        $ran = &$this->ran;
        $ps = $this->ps;
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileGetContents')->willReturn(GeneratedCompose::withProcesses(
            $this->compose ?? DeployCompose::dockerfile('Dockerfile', 3000),
            ['release' => 'node migrate.js']
        ));
        $system = new class ($ran, $release, $this->build, $filesystem, $ps) extends System {
            /** @param list<string> $ran */
            public function __construct(
                private array &$ran,
                private string $release,
                private string $build,
                private Filesystem $files,
                private ?string $ps,
            ) {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (!str_contains(implode(' ', (array) $cmd), "'ps' '--quiet' '--status' 'running'")) {
                    throw new \RuntimeException('unexpected command');
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
                    'build' => $this->build,
                    'run' => $this->release,
                    default => 'echo "up stopped here" >&2; exit 1',
                };
                $process = new Process(['sh', '-c', $script]);
                $process->run();

                return $process;
            }
        };

        $user = new User();
        $user->username = 'release-' . bin2hex(random_bytes(4));
        $user->setDetails(['deploy_strategy' => 'dockerfile']);

        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($this->createStub(InnerDocker::class));
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
