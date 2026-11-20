<?php

namespace Tests\Integration\System\Project\Dind;

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

    public function test_the_host_falling_under_its_minimum_stops_the_step(): void
    {
        $limit = new StepDiskLimit($this->host(['/var/lib/docker' => 30 * 1024, '/home' => 2900], 0), 3 * self::GB, '/home/acme', null);

        $this->assertSame('the engine host has 2.8G free on /home, under DEPLOY_HOST_MIN_FREE (3G)', $limit->reason());
    }

    public function test_a_project_over_its_disk_limit_stops_the_step(): void
    {
        $host = $this->host(['/var/lib/docker' => 30 * 1024, '/home' => 30 * 1024], 2100 * 1024);
        $limit = new StepDiskLimit($host, 3 * self::GB, '/home/acme', 2000);

        $this->assertSame('the project uses 2.1G, over its 2G disk limit', $limit->reason());
        $this->assertContains('du -sxk /home/acme', $host->asked);
    }
}
