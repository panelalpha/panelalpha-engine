<?php

namespace Tests\Unit\Support;

use App\Support\SitesDbCaches;
use App\System;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the running sites-db reports for its caches, and the sizes an operator may type. */
class SitesDbCachesReadingsTest extends TestCase
{
    public function test_the_running_sizes_are_read_in_megabytes(): void
    {
        $this->assertSame([
            'SITES_DB_INNODB_BUFFER_POOL_SIZE' => 32,
            'SITES_DB_KEY_BUFFER_SIZE' => 8,
            'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE' => 1,
        ], $this->answering("33554432 8388608\t1048576\n")->running());
    }

    /** @return array<string, array{string}> */
    public static function unreadable(): array
    {
        return [
            'two sizes for three caches' => ['33554432 8388608'],
            'a size that is not a number' => ['33554432 8M 1048576'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_an_answer_that_is_not_three_byte_counts_is_not_a_reading(string $output): void
    {
        $this->assertNull($this->answering($output)->running());
    }

    public function test_kilobytes_round_down_to_whole_megabytes(): void
    {
        $this->assertSame(2, SitesDbCaches::megabytes('2048k'));
        $this->assertSame(0, SitesDbCaches::megabytes('512K'));
        $this->assertSame('At least 1M.', SitesDbCaches::badSize('SITES_DB_KEY_BUFFER_SIZE', '512K', 4096));
    }

    private function answering(string $output): SitesDbCaches
    {
        $system = $this->createStub(System::class);
        $system->method('engineDirPath')->willReturn('/opt/panelalpha/shared-hosting');
        $system->method('exec')->willReturn($output);

        return new SitesDbCaches($system);
    }
}
