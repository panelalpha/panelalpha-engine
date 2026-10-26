<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRefsUnavailableException;
use App\Lib\Deploy\Source\GitRemoteProbe;
use PHPUnit\Framework\TestCase;

/**
 * The probe runs from the core container, so it may only speak the transports
 * the engine itself clones over.
 */
class GitRemoteProbeTransportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/probe-transport-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
        parent::tearDown();
    }

    public function test_the_probe_does_not_run_a_transport_the_engine_cannot_clone(): void
    {
        $repository = $this->bareRepositoryWithOneBranch();

        $this->expectException(GitRefsUnavailableException::class);

        (new GitRemoteProbe(10, 1))->refs('file://' . $repository, null);
    }

    private function bareRepositoryWithOneBranch(): string
    {
        $repository = $this->dir . '/origin.git';
        $seed = $this->dir . '/seed';

        self::git($this->dir, 'init -q --bare --initial-branch=main ' . escapeshellarg($repository));
        mkdir($seed);
        self::git($seed, 'init -q --initial-branch=main');
        file_put_contents($seed . '/readme', "probe\n");
        self::git($seed, 'add -A');
        self::git($seed, '-c user.name=t -c user.email=t@example.com commit -q -m seed');
        self::git($seed, 'push -q ' . escapeshellarg($repository) . ' main');

        return $repository;
    }

    private static function git(string $cwd, string $arguments): void
    {
        exec('cd ' . escapeshellarg($cwd) . ' && git ' . $arguments . ' 2>&1', $output, $status);
        if ($status !== 0) {
            self::fail('git ' . $arguments . ' failed: ' . implode("\n", $output));
        }
    }

    private static function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
