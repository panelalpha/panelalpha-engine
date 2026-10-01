<?php

namespace Tests\Unit\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\Filesystem;
use App\System\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * engine#524: writing an account's template files must not open whatever is
 * already at the target path, because a tenant who still has that directory
 * mounted read-write can put a symlink there between two commands that would
 * otherwise `rm` then `cp` it back. `LocalHostSystem` applies `sudo` file
 * commands to a real temp tree, so these run the actual `cp`/`mv`/`test -L`
 * the engine issues -- it is PHP's own `copy()`/`rename()` underneath, but
 * their symlink behaviour matches the coreutils they stand in for.
 */
class FilesystemSafeWriteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-fs-safe-write-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function filesystem(): Filesystem
    {
        return new Filesystem(new LocalHostSystem($this->dir, $this->dir));
    }

    public function test_the_file_is_written(): void
    {
        $path = $this->dir . '/useradd.sh';

        $this->filesystem()->writeFileReplacingPath($path, 'echo hi');

        $this->assertSame('echo hi', file_get_contents($path));
    }

    public function test_a_symlink_at_the_target_is_replaced_not_followed(): void
    {
        $outside = $this->dir . '/../outside-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'original content');
        $path = $this->dir . '/useradd.sh';
        symlink($outside, $path);

        $this->filesystem()->writeFileReplacingPath($path, 'new content');

        $this->assertSame('original content', file_get_contents($outside), 'the symlinked file must be untouched');
        $this->assertFalse(is_link($path), 'the symlink itself must be gone');
        $this->assertSame('new content', file_get_contents($path));

        unlink($outside);
    }

    public function test_no_intermediate_state_is_visible_at_the_final_name(): void
    {
        // The write happens as a single rename, never a window where the
        // final name is briefly missing or a temp file under its own name.
        $filesystem = $this->filesystem();
        // A second write right after the first proves the first left nothing
        // behind under the target's own name (a stray ".tmp-..." file would
        // otherwise accumulate next to it).
        $filesystem->writeFileReplacingPath($this->dir . '/useradd.sh', 'one');
        $filesystem->writeFileReplacingPath($this->dir . '/useradd.sh', 'two');

        $leftovers = array_filter(scandir($this->dir) ?: [], static fn (string $f): bool => $f !== 'useradd.sh' && $f[0] !== '.');
        $this->assertSame([], array_values($leftovers), 'no temp file left beside the target');
        $this->assertSame('two', file_get_contents($this->dir . '/useradd.sh'));
    }

    public function test_an_in_place_overwrite_keeps_the_inode(): void
    {
        $path = $this->dir . '/daemon.json';
        file_put_contents($path, 'old');
        $inodeBefore = fileinode($path);

        $wrote = $this->filesystem()->overwriteFileUnlessSymlink($path, 'new');

        $this->assertTrue($wrote);
        $this->assertSame('new', file_get_contents($path));
        clearstatcache(true, $path);
        $this->assertSame($inodeBefore, fileinode($path), 'a bind mount on this file would not see a new inode');
    }

    public function test_an_in_place_overwrite_refuses_a_symlinked_target(): void
    {
        $outside = $this->dir . '/../outside-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'original content');
        $path = $this->dir . '/daemon.json';
        symlink($outside, $path);

        $wrote = $this->filesystem()->overwriteFileUnlessSymlink($path, 'attacker would want this written');

        $this->assertFalse($wrote);
        $this->assertSame('original content', file_get_contents($outside), 'the write must never follow the symlink');

        unlink($outside);
    }

    /** The commands themselves: a temp name next to the target, then `mv -T`. */
    public function test_the_final_step_is_mv_dash_t(): void
    {
        $calls = [];
        $runner = new class ($calls) implements ProcessRunner {
            /** @param list<array{0:string|array,1:array}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = $cmd;

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->calls[] = $cmd;
                $argv = is_array($cmd) ? $cmd : [];
                // Answer `sudo test -d` for real so makeDirWithParents() does
                // not loop forever over a fake "no directory exists anywhere".
                $exit = ($argv[1] ?? null) === 'test' && ($argv[2] ?? null) === '-d' && is_dir($argv[3] ?? '') ? 0 : 1;

                return new class ($exit) extends Process {
                    public function __construct(private int $code)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->code;
                    }
                };
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['true']);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return new Process(['true']);
            }
        };

        (new Filesystem($runner))->writeFileReplacingPath($this->dir . '/useradd.sh', 'content');

        $last = end($calls);
        $this->assertIsArray($last);
        $this->assertSame('sudo', $last[0]);
        $this->assertSame('mv', $last[1]);
        $this->assertSame('-T', $last[2]);
        $this->assertSame($this->dir . '/useradd.sh', $last[4], 'renames onto the real target name, not a derived one');
        $this->assertStringStartsWith($this->dir . '/useradd.sh.tmp-', (string) $last[3], 'the temp name sits next to the target, same directory');
    }
}
