<?php

namespace Tests\Support;

use App\System;

trait StepDiskLimitFixtures
{
    private const GB = 1073741824;

    /**
     * @param array<string, int> $freeMb df free space per path in MB; a missing path fails
     */
    private function host(array $freeMb, ?int $homeKb): System
    {
        return new class ($freeMb, $homeKb) extends System {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private array $freeMb, private ?int $homeKb)
            {
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->asked[] = $line;
                $path = is_array($cmd) ? (string) end($cmd) : '';
                if (str_starts_with($line, 'du ')) {
                    if ($this->homeKb === null) {
                        throw new \RuntimeException('du failed');
                    }

                    return "{$this->homeKb}\t{$path}\n";
                }
                if (!isset($this->freeMb[$path])) {
                    throw new \RuntimeException("df: {$path}: No such file or directory");
                }

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . '/dev/sda1 157286400 1000 ' . ($this->freeMb[$path] * 1024) . " 30% {$path}\n";
            }
        };
    }
}
