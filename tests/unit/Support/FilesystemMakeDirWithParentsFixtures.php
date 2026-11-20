<?php

namespace Tests\Support;

use App\System;
use Symfony\Component\Process\Process;

trait FilesystemMakeDirWithParentsFixtures
{
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
