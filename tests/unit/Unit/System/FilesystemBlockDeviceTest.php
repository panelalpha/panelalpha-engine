<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * engine#792: a /home on a whole, unpartitioned disk (for example /dev/vdb
 * mounted directly) has an empty `lsblk -no PKNAME`, so the old code prepended
 * `/dev/` to nothing and a compose file's blkio `path:` came out `/dev/`.
 * A filesystem that is not a block device at all must be refused, not rendered.
 */
class FilesystemBlockDeviceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The filesystem info and the device are cached per process.
        foreach (['homeFilesystemInfo', 'homeFilesystemParentBlockDevice'] as $name) {
            $property = new \ReflectionProperty(Filesystem::class, $name);
            $property->setValue(null, null);
        }
    }

    public function test_a_partition_uses_its_parent_disk(): void
    {
        $fs = new Filesystem($this->system([
            'df -P /home | tail -1' => "/dev/sda1 100 10 500 1% /home\n",
            'lsblk -no PKNAME /dev/sda1' => "sda\n",
        ]));

        $this->assertSame('/dev/sda', $fs->getHomeFilesystemParentBlockDevice());
    }

    public function test_a_whole_unpartitioned_disk_uses_itself(): void
    {
        $fs = new Filesystem($this->system([
            'df -P /home | tail -1' => "/dev/vdb 100 10 500 1% /home\n",
            'lsblk -no PKNAME /dev/vdb' => '',
            'lsblk -no TYPE /dev/vdb' => "disk\n",
        ]));

        $this->assertSame('/dev/vdb', $fs->getHomeFilesystemParentBlockDevice());
    }

    public function test_a_filesystem_that_is_not_a_block_device_is_refused(): void
    {
        $fs = new Filesystem($this->system([
            'df -P /home | tail -1' => "rpool/home 100 10 500 1% /home\n",
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('block device could not be found');

        $fs->getHomeFilesystemParentBlockDevice();
    }

    /** @param array<string, string> $answers */
    private function system(array $answers): System
    {
        return new class ($answers) extends System {
            /** @param array<string, string> $answers */
            public function __construct(private array $answers)
            {
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->answers[implode(' ', (array) $cmd)] ?? '';
            }
        };
    }
}
