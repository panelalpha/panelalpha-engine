<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostImageRetention;
use PHPUnit\Framework\TestCase;

class HostImageRetentionTagFilterTest extends TestCase
{
    private const NOW = 1800000000;

    private const DAY = 86400;

    public function test_untagged_empty_and_unsafe_tags_are_never_candidates(): void
    {
        $old = self::NOW - 10 * self::DAY;
        $rows = [
            ['tag' => '<none>:<none>', 'bytes' => 1, 'since' => $old],
            ['tag' => '', 'bytes' => 1, 'since' => $old],
            ['tag' => '-bad:1', 'bytes' => 1, 'since' => $old],
            ['tag' => 'golang:1.99', 'bytes' => 5, 'since' => $old],
        ];

        $this->assertSame(
            ['golang:1.99' => ['tags' => ['golang:1.99'], 'bytes' => 5, 'since' => $old]],
            HostImageRetention::candidates($rows, [], ['golang:1.22'], [], self::NOW, 3 * self::DAY)
        );
    }
}
