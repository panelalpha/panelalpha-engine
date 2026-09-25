<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FilesystemMakeDirWithParentsTest extends TestCase
{
    public function test_creates_missing_parents_top_down(): void
    {
        $system = $this->system(['/', '/srv']);

        (new Filesystem($system))->makeDirWithParents('/srv/a/b', '1000:1000');

        $this->assertSame([
            ['sudo', 'mkdir', '/srv/a'],
            ['sudo', 'chown', '1000:1000', '/srv/a'],
            ['sudo', 'mkdir', '/srv/a/b'],
            ['sudo', 'chown', '1000:1000', '/srv/a/b'],
        ], $system->execs);
    }

    public function test_throws_instead_of_looping_when_root_is_not_a_directory(): void
    {
        // What a missing or password-prompting sudo looks like: every `test -d` fails.
        $system = $this->system([]);

        try {
            (new Filesystem($system))->makeDirWithParents('/srv/a/b');
            $this->fail('Expected makeDirWithParents() to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'/' does not read as a directory", $e->getMessage());
        }

        $this->assertSame(['/srv/a/b', '/srv/a', '/srv', '/'], $system->probes);
        $this->assertSame([], $system->execs);
    }

    public function test_relative_path_stops_at_dot(): void
    {
        $system = $this->system([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'.' does not read as a directory");

        (new Filesystem($system))->makeDirWithParents('a/b');
    }

    /**
     * @param list<string> $dirs paths `sudo test -d` reports as existing
     */
    private function system(array $dirs): System
    {
        return new class ($dirs) extends System {
            /** @var list<string> */
            public array $probes = [];
            /** @var list<array<string>> */
            public array $execs = [];

            /** @param list<string> $dirs */
            public function __construct(private array $dirs)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $path = end($cmd);
                $this->probes[] = $path;

                return new class (in_array($path, $this->dirs, true) ? 0 : 1) extends Process {
                    public function __construct(private int $fakeExitCode)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->fakeExitCode;
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->execs[] = $cmd;

                return '';
            }
        };
    }
}
