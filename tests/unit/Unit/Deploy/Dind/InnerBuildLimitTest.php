<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\InnerBuildLimit;
use App\Lib\Host\HostMemory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InnerBuildLimitFixtures;

/**
 * A build in the account's own Docker took the whole account -- 87% of a
 * 3.8 GB host -- with its RUN steps at dockerd's -500, below the app. It now
 * gets the host build ceiling, never above the project's limit, and 1000.
 */
class InnerBuildLimitTest extends TestCase
{
    use InnerBuildLimitFixtures;

    #[DataProvider('limits')]
    public function test_the_build_gets_the_lower_of_the_ceiling_and_the_project(int $ceilingMb, int $projectMb, int $expected): void
    {
        $this->assertSame($expected, InnerBuildLimit::megabytes($ceilingMb, $projectMb));
    }

    public static function limits(): array
    {
        return [
            'a project larger than the ceiling' => [8192, 23934, 8192],
            'a project smaller than the ceiling' => [8192, 1024, 1024],
            'an unknown project' => [1895, 0, 1895],
            'an unknown ceiling' => [0, 1024, 1024],
            'nothing known' => [0, 0, 0],
        ];
    }

    /** The host the owner saw go down: 2 CPU, 3.8 GB, no swap, a project at its default. */
    public function test_a_small_host_holds_the_build_to_half_its_ram(): void
    {
        $host = new HostMemory(3790);
        $ceiling = (new DindHostBuilder(DindEngine::buildMemory('', $host)->limit, ''))->memoryLimitMb();

        $this->assertSame(1895, InnerBuildLimit::megabytes($ceiling, $host->maxProjectMb()));
        $this->assertSame(3278, $host->maxProjectMb(), 'the account alone would have let it take this');
    }

    public function test_the_apply_command_carries_every_value_as_an_argument(): void
    {
        $argv = InnerBuildLimit::applyCommand(1895, 'abc123');

        $this->assertSame(['sh', '-c'], array_slice($argv, 0, 2));
        $this->assertStringStartsWith('set -e', $argv[2]);
        $this->assertSame([
            'build-guard',
            '/sys/fs/cgroup/docker/buildkit',
            (string) (1895 * 1024 * 1024),
            '/run/panelalpha-build-guard',
            'abc123',
            '7200',
            '1000',
        ], array_slice($argv, 3));
    }

    public function test_an_unknown_limit_leaves_the_build_uncapped_but_still_guarded(): void
    {
        $argv = InnerBuildLimit::applyCommand(0, 'abc123');

        $this->assertSame('max', $argv[5]);
        $this->assertSame('1000', $argv[9]);
    }

    public function test_the_release_command_names_the_guard_file_and_its_token(): void
    {
        $argv = InnerBuildLimit::releaseCommand('abc123');

        $this->assertSame(['sh', '-c'], array_slice($argv, 0, 2));
        $this->assertSame(['build-guard-release', '/run/panelalpha-build-guard', 'abc123'], array_slice($argv, 3));
    }
}
