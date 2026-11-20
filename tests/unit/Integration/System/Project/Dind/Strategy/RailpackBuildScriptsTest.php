<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

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
 * The prepare script a Railpack build writes into the account and the commands it runs, in order,
 * on the paths where railpack is missing, the plan cannot be read, prepare fails or the build does.
 */
class RailpackBuildScriptsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private ?string $failOn = null;

    private string|\Throwable $plan = '{"steps":[]}';

    private bool $scratchExists = true;

    /** @var list<list<string>> */
    private array $preloaded = [];

    public function test_a_successful_build_writes_the_prepare_script_and_runs_the_build_as_the_account(): void
    {
        $this->assertTrue($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));

        $this->assertSame($this->expectedSuccess(), $this->events);
        $this->assertCount(1, $this->preloaded);
    }

    public function test_railpack_missing_skips_the_build(): void
    {
        $this->failOn = 'which';

        $this->assertFalse($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));

        $this->assertSame(['which'], array_column($this->events, 'step'));
        $this->assertSame([], $this->preloaded);
    }

    public function test_a_failed_prepare_still_removes_the_scripts(): void
    {
        $this->failOn = 'prepare';

        $this->assertFalse($this->railpack()->buildImage('/home/alice/project', null, 'img:latest'));

        $this->assertSame(['which', 'write', 'prepare', 'rm'], array_column($this->events, 'step'));
        $this->assertSame(Privileged::files()->removeIfExists('/home/alice/.panelalpha/railpack-build-tmp.sh', '/home/alice/.panelalpha/railpack-plan-tmp.json'), end($this->events)['cmd']);
    }

    public function test_an_unreadable_plan_still_builds(): void
    {
        $this->plan = new \RuntimeException('gone');

        $this->assertTrue($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));

        $this->assertSame(['which', 'write', 'prepare', 'build-guard', 'build', 'build-guard-release', 'rm'], array_column($this->events, 'step'));
    }

    public function test_a_missing_scratch_dir_is_made_for_the_account(): void
    {
        $this->scratchExists = false;
        $this->failOn = 'build';

        $this->assertFalse($this->railpack()->buildImage('/home/alice/project', '1000:1000', 'panelalpha-alice-app:latest'));

        $steps = array_column($this->events, 'step');
        $this->assertSame(['which', 'make-home-entry', 'write', 'prepare', 'build-guard', 'build', 'build-guard-release', 'rm'], $steps);
        $this->assertSame(Privileged::files()->removeIfExists('/home/alice/railpack-build-tmp.sh', '/home/alice/railpack-plan-tmp.json'), end($this->events)['cmd']);
    }

    /** @return list<array<string, mixed>> */
    private function expectedSuccess(): array
    {
        $prepare = "#!/bin/bash\nset -e\ncd '/home/alice/project'\nrailpack prepare . --plan-out '/home/alice/.panelalpha/railpack-plan-tmp.json'\n";
        $build = [
            'docker', 'buildx', 'build', '--build-arg', 'BUILDKIT_SYNTAX=' . \App\Lib\Deploy\CacheManager\RailpackCache::FRONTEND_IMAGE,
            '-f', '/home/alice/.panelalpha/railpack-plan-tmp.json', '--output', 'type=docker,name=panelalpha-alice-app:latest', '.',
        ];

        return [
            ['step' => 'which'],
            ['step' => 'write', 'path' => '/home/alice/.panelalpha/railpack-build-tmp.sh', 'contents' => $prepare, 'chown' => '1000:1000', 'mode' => '700'],
            ['step' => 'prepare', 'timeout' => 900],
            ['step' => 'build-guard'],
            ['step' => 'build', 'as' => '', 'environment' => ['PWD' => '/home/alice/project'], 'client' => $build, 'timeout' => 3600],
            ['step' => 'build-guard-release'],
            ['step' => 'rm', 'cmd' => Privileged::files()->removeIfExists('/home/alice/.panelalpha/railpack-build-tmp.sh', '/home/alice/.panelalpha/railpack-plan-tmp.json')],
        ];
    }

    private function railpack(): RailpackStrategy
    {
        $files = $this->createStub(Filesystem::class);
        $files->method('writeFileReplacingPath')->willReturnCallback(
            function (string $path, string $contents, ?string $chown = null, ?string $mode = null): void {
                $this->events[] = ['step' => 'write', 'path' => $path, 'contents' => $contents, 'chown' => $chown, 'mode' => $mode];
            }
        );
        $files->method('fileGetContents')->willReturnCallback(function (): string {
            if ($this->plan instanceof \Throwable) {
                throw $this->plan;
            }

            return $this->plan;
        });

        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($files);
        $system->method('directoryExists')->willReturnCallback(fn (): bool => $this->scratchExists);
        $system->method('exec')->willReturnCallback(function (string|array $cmd, array $env = [], int $timeout = 0): string {
            $argv = array_values((array) $cmd);
            $event = $this->event($argv, $timeout);
            $this->events[] = $event;
            if ($this->failOn === $event['step']) {
                throw new \RuntimeException("failed at {$event['step']}");
            }

            return '';
        });

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder('8192m', ''));

        $user = new User;
        $user->username = 'alice';
        $user->setDetails(['UID' => 1000, 'GID' => 1000]);

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('preloadRailpackBaseImages')->willReturnCallback(function (array $images): void {
            $this->preloaded[] = $images;
        });

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('engine')->willReturn($engine);
        $project->method('userModel')->willReturn($user);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice/');
        $project->method('composeFilePath')->willReturn('/opt/users/alice/docker-compose.yml');
        $project->method('innerDocker')->willReturn($inner);
        $project->method('registryLogin')->willReturn(new RegistryLogin($project));
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));

        return new RailpackStrategy($project);
    }

    /**
     * @param  list<string>  $argv
     * @return array<string, mixed>
     */
    private function event(array $argv, int $timeout): array
    {
        $guard = array_values(preg_grep('/^build-guard/', $argv) ?: []);
        if ($guard !== []) {
            return ['step' => $guard[0]];
        }
        if (LocalRoot::action($argv) === 'files.removeIfExists') {
            return ['step' => 'rm', 'cmd' => $argv];
        }
        if ($argv === Privileged::scripts()->makeHomeEntry('/home/alice/.panelalpha', '0700', '1000:1000')) {
            return ['step' => 'make-home-entry'];
        }
        if (LocalRoot::composeCommand($argv) !== []) {
            return [
                'step' => 'build',
                'as' => LocalRoot::values($argv)[1],
                'environment' => LocalRoot::accountEnvironment($argv),
                'client' => LocalRoot::composeCommand($argv),
                'timeout' => $timeout,
            ];
        }
        if (str_contains((string) end($argv), "'which' 'railpack'")) {
            return ['step' => 'which'];
        }

        return ['step' => 'prepare', 'timeout' => $timeout];
    }
}
