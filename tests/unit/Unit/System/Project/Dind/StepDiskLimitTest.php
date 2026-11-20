<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\StepDiskLimit;
use PHPUnit\Framework\TestCase;
use Tests\Support\StepDiskLimitFixtures;

/**
 * What stops a running deploy step for disk -- the host minimum
 * and the project's own limit, which user quota never applied to the
 * account's Docker store.
 */
class StepDiskLimitTest extends TestCase
{
    use StepDiskLimitFixtures;

    public function test_room_on_both_carries_on(): void
    {
        $limit = new StepDiskLimit($this->host(['/var/lib/docker' => 30 * 1024, '/home' => 30 * 1024], 500 * 1024), 3 * self::GB, '/home/acme', 2000);

        $this->assertNull($limit->reason());
    }

    public function test_unlimited_projects_are_never_measured(): void
    {
        $host = $this->host(['/var/lib/docker' => 30 * 1024, '/home' => 30 * 1024], 99 * 1024 * 1024);

        $this->assertNull((new StepDiskLimit($host, 3 * self::GB, '/home/acme', -1))->reason());
        $this->assertNull((new StepDiskLimit($host, null, '/home/acme', null))->reason());
        $this->assertSame([], array_values(array_filter($host->asked, static fn (string $c): bool => str_starts_with($c, 'du '))));
    }

    public function test_unreadable_disks_carry_on(): void
    {
        $this->assertNull((new StepDiskLimit($this->host([], null), 3 * self::GB, '/home/acme', 2000))->reason());
    }
}
