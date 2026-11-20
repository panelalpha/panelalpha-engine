<?php

namespace Tests\Support;

use App\System;

trait HostDiskGuardFixtures
{
    /**
     * @param array<string, int> $freeMb free space per path in MB; a missing path fails df
     */
    private function host(array $freeMb): System
    {
        return new class ($freeMb) extends System {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string, int> $freeMb */
            public function __construct(private array $freeMb)
            {
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $this->asked[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $path = is_array($cmd) ? (string) end($cmd) : '';
                if (!isset($this->freeMb[$path])) {
                    throw new \RuntimeException("df: {$path}: No such file or directory");
                }

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . '/dev/sda1 157286400 1000 ' . ($this->freeMb[$path] * 1024) . " 30% {$path}\n";
            }
        };
    }
}
