<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\InnerBuildLimit;
use App\Lib\Host\HostMemory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A build in the account's own Docker took the whole account -- 87% of a
 * 3.8 GB host -- with its RUN steps at dockerd's -500, below the app. It now
 * gets the host build ceiling, never above the project's limit, and 1000.
 */
class InnerBuildLimitTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<resource> */
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child, 9);
            proc_close($child);
        }
        foreach (array_reverse($this->dirs) as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

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

    /**
     * The scripts themselves, against a directory standing in for the cgroup
     * and a child of this test standing in for a RUN step's process.
     */
    public function test_the_scripts_cap_the_cgroup_raise_the_step_and_stop_on_release(): void
    {
        $this->requireProcAndSetsid();
        $root = $this->tempDir();
        $cgroup = $root . '/docker/buildkit';
        mkdir($cgroup . '/step1', 0777, true);
        touch($cgroup . '/memory.swap.max');
        $guardFile = $root . '/guard';
        $pid = $this->child();
        file_put_contents($cgroup . '/step1/cgroup.procs', $pid . "\n");

        $this->runScript(self::withPaths(InnerBuildLimit::applyCommand(1895, 'tok-a'), $cgroup, $guardFile, '30'));

        $this->assertSame((string) (1895 * 1024 * 1024), trim((string) file_get_contents($cgroup . '/memory.max')));
        $this->assertSame('0', trim((string) file_get_contents($cgroup . '/memory.swap.max')));
        $this->assertSame('+memory', trim((string) file_get_contents($root . '/docker/cgroup.subtree_control')));
        $this->assertTrue($this->eventually(fn (): bool => $this->oomScoreAdj($pid) === '1000'), 'the step was raised');
        $this->assertNotSame([], $this->guardsWatching($guardFile));

        $this->runScript(self::withRelease(InnerBuildLimit::releaseCommand('tok-other'), $guardFile));
        $this->assertFileExists($guardFile, 'another build\'s release leaves this guard alone');

        $this->runScript(self::withRelease(InnerBuildLimit::releaseCommand('tok-a'), $guardFile));
        $this->assertFileDoesNotExist($guardFile);
        $this->assertTrue($this->eventually(fn (): bool => $this->guardsWatching($guardFile) === []), 'the guard stopped');
    }

    /** A second build's guard takes over: the first one's stops, and its release does not end the second. */
    public function test_a_newer_guard_replaces_an_older_one(): void
    {
        $this->requireProcAndSetsid();
        $root = $this->tempDir();
        $cgroup = $root . '/docker/buildkit';
        mkdir($cgroup, 0777, true);
        $guardFile = $root . '/guard';

        $this->runScript(self::withPaths(InnerBuildLimit::applyCommand(512, 'tok-a'), $cgroup, $guardFile, '30'));
        $this->runScript(self::withPaths(InnerBuildLimit::applyCommand(512, 'tok-b'), $cgroup, $guardFile, '30'));
        $this->assertTrue($this->eventually(fn (): bool => count($this->guardsWatching($guardFile)) === 1));

        $this->runScript(self::withRelease(InnerBuildLimit::releaseCommand('tok-a'), $guardFile));
        $this->assertSame('tok-b', trim((string) file_get_contents($guardFile)));
        $this->runScript(self::withRelease(InnerBuildLimit::releaseCommand('tok-b'), $guardFile));
        $this->assertTrue($this->eventually(fn (): bool => $this->guardsWatching($guardFile) === []));
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function withPaths(array $argv, string $cgroup, string $guardFile, string $seconds): array
    {
        $argv[4] = $cgroup;
        $argv[6] = $guardFile;
        $argv[8] = $seconds;

        return $argv;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function withRelease(array $argv, string $guardFile): array
    {
        $argv[4] = $guardFile;

        return $argv;
    }

    /** @param  list<string>  $argv */
    private function runScript(array $argv): void
    {
        exec(implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    private function child(): int
    {
        $child = proc_open(['sleep', '60'], [], $pipes);
        $this->assertIsResource($child);
        $this->children[] = $child;

        return (int) proc_get_status($child)['pid'];
    }

    private function oomScoreAdj(int $pid): string
    {
        return trim((string) @file_get_contents("/proc/{$pid}/oom_score_adj"));
    }

    /** @return list<string> pids of guard loops reading $guardFile */
    private function guardsWatching(string $guardFile): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $argv = explode("\0", (string) @file_get_contents($file));
            if (($argv[3] ?? '') === 'build-guard' && ($argv[4] ?? '') === $guardFile) {
                $pids[] = basename(dirname($file));
            }
        }

        return $pids;
    }

    private function eventually(callable $check): bool
    {
        for ($i = 0; $i < 50; $i++) {
            if ($check()) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/pa-build-guard-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function requireProcAndSetsid(): void
    {
        exec('command -v setsid', $output, $status);
        if (PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/oom_score_adj') || $status !== 0) {
            $this->markTestSkipped('needs Linux /proc and setsid');
        }
    }
}
