<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\InnerBuildLimit;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The guard is set as root in the account before a build and stopped after
 * it, whatever the build did, and never stands in the build's way.
 */
class InnerBuildGuardTest extends TestCase
{
    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<list<string>> */
    private array $requests = [];

    private ?\Throwable $failApply = null;

    public function test_the_build_runs_between_apply_and_release(): void
    {
        $guard = new InnerBuildGuard($this->project('8192m', 23934));
        $seen = null;

        $result = $guard->during(function () use (&$seen): string {
            $seen = count($this->commands);

            return 'built';
        });

        $this->assertSame('built', $result);
        $this->assertSame(1, $seen, 'applied before the build');
        $this->assertCount(2, $this->commands);
        $apply = $this->inner($this->commands[0]);
        $release = $this->inner($this->commands[1]);
        $this->assertSame('build-guard', $apply[3]);
        $this->assertSame((string) (8192 * 1024 * 1024), $apply[5]);
        $this->assertSame(['build-guard-release', InnerBuildLimit::GUARD_FILE, $apply[7]], array_slice($release, 3));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $apply[7]);
    }

    /** Root in the account's own container, through the host daemon. */
    public function test_it_runs_as_root_in_the_account(): void
    {
        (new InnerBuildGuard($this->project('8192m', 1024)))->during(static fn () => null);

        $this->assertSame('account-container.exec', LocalRoot::action($this->requests[0]));
        $this->assertSame(['alice', '', ''], array_slice(LocalRoot::values($this->requests[0]), 0, 3));
    }

    public function test_the_limit_is_the_ceiling_held_to_the_project(): void
    {
        $this->assertSame(1024, (new InnerBuildGuard($this->project('8192m', 1024)))->megabytes());
        $this->assertSame(1895, (new InnerBuildGuard($this->project('1895m', 3278)))->megabytes());
    }

    public function test_a_failed_build_is_released_and_rethrown(): void
    {
        $guard = new InnerBuildGuard($this->project('8192m', 1024));

        try {
            $guard->during(static function (): void {
                throw new \RuntimeException('build failed');
            });
            $this->fail('the build\'s failure is the caller\'s');
        } catch (\RuntimeException $e) {
            $this->assertSame('build failed', $e->getMessage());
        }

        $this->assertSame('build-guard-release', $this->inner($this->commands[1])[3]);
    }

    public function test_nested_builds_share_one_guard(): void
    {
        $guard = new InnerBuildGuard($this->project('8192m', 1024));

        $guard->during(fn () => $guard->during(static fn () => null));

        $this->assertSame(['build-guard', 'build-guard-release'], array_map(fn (array $c): string => $this->inner($c)[3], $this->commands));
    }

    /** No cgroup, no guard: the build still runs, and there is nothing to release. */
    public function test_a_guard_that_cannot_be_set_does_not_stop_the_build(): void
    {
        $this->failApply = new \RuntimeException('mkdir: cannot create directory: Read-only file system');
        $guard = new InnerBuildGuard($this->project('8192m', 1024));

        $this->assertSame('built', $guard->during(static fn (): string => 'built'));
        $this->assertCount(1, $this->commands);
    }

    public function test_the_deploy_log_line_names_the_figure(): void
    {
        $this->assertStringStartsWith('Image build memory: 1895 MB', InnerBuildGuard::announcement(1895));
    }

    /**
     * @param  list<string>  $argv
     * @return list<string> what runs inside the account
     */
    private function inner(array $argv): array
    {
        return LocalRoot::inAccount($argv);
    }

    private function project(string $buildCeiling, int $projectMb): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $this->requests[] = array_values((array) $cmd);
            $this->commands[] = LocalRoot::plain(array_values((array) $cmd));
            if ($this->failApply !== null && in_array('build-guard', (array) $cmd, true)) {
                throw $this->failApply;
            }

            return '';
        });

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder($buildCeiling, ''));

        $user = $this->createStub(User::class);
        $user->method('effectiveMemoryLimit')->willReturn($projectMb);
        $user->method('getChownString')->willReturn('1000:1000');

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('engine')->willReturn($engine);
        $project->method('userModel')->willReturn($user);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }
}
