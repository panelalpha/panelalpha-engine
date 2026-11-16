<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FilesystemMakeDirWithParentsTest extends TestCase
{
    public function test_creates_missing_parents_top_down(): void
    {
        $system = $this->system(['/', '/srv']);

        (new Filesystem($system))->makeDirWithParents('/srv/a/b', '1000:1000');

        $this->assertSame([
            Privileged::files()->makeDirectory('/srv/a'),
            Privileged::files()->changeOwner('1000:1000', '/srv/a'),
            Privileged::files()->makeDirectory('/srv/a/b'),
            Privileged::files()->changeOwner('1000:1000', '/srv/a/b'),
        ], $system->execs);
    }

    /** Every directory probe fails, as with a missing or refusing root broker. */
    public function test_throws_instead_of_looping_when_root_is_not_a_directory(): void
    {
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

    public function test_a_relative_path_is_refused_before_anything_runs(): void
    {
        $system = $this->system([]);

        try {
            (new Filesystem($system))->makeDirWithParents('a/b');
            $this->fail('Expected makeDirWithParents() to refuse a relative path');
        } catch (InvalidArgument $e) {
            $this->assertSame('Refusing path "a/b" for a root command', $e->getMessage());
        }

        $this->assertSame([], $system->probes);
        $this->assertSame([], $system->execs);
    }

    /**
     * @param list<string> $dirs paths the root directory probe reports as existing
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
