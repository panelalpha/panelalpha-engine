<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FilesystemAssertFreeSpaceTest extends TestCase
{
    public function test_assert_free_space_passes_when_enough(): void
    {
        $system = $this->recordingSystem([
            'df -P /var/tmp' => "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 100 10 5000000 1% /var/tmp\n",
        ]);
        $fs = new Filesystem($system);

        $fs->assertFreeSpace(1024);

        $this->assertTrue(true);
    }

    public function test_assert_free_space_throws_when_insufficient(): void
    {
        $system = $this->recordingSystem([
            'df -P /var/tmp' => "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 100 10 1 1% /var/tmp\n",
        ]);
        $fs = new Filesystem($system);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient free space');

        $fs->assertFreeSpace(10 * 1024 * 1024);
    }

    public function test_assert_free_space_falls_back_to_home(): void
    {
        $system = $this->recordingSystem([
            'df -P /var/tmp' => null,
            'df -P /home' => "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 100 10 5000000 1% /home\n",
        ]);
        $fs = new Filesystem($system);

        $fs->assertFreeSpace(1024);

        $this->assertTrue(true);
    }

    /**
     * @param array<string, string|null> $dfOutputs keyed by "df -P PATH"; null = throw
     */
    private function recordingSystem(array $dfOutputs): System
    {
        return new class ($dfOutputs) extends System {
            /** @param array<string, string|null> $dfOutputs */
            public function __construct(private array $dfOutputs)
            {
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $key = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (!array_key_exists($key, $this->dfOutputs)) {
                    throw new \RuntimeException('Unexpected execOnHost: ' . $key);
                }
                $output = $this->dfOutputs[$key];
                if ($output === null) {
                    throw new \RuntimeException('df failed');
                }

                return $output;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \RuntimeException('Unexpected runProcess');
            }
        };
    }
}
