<?php

namespace Tests\Unit\Host;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Lib\Host\ProjectMemory;
use App\Rules\AccountMemoryLimit;
use Tests\TestCase;

/**
 * How much memory projects may have (#294). Figures are mariusz2's, measured
 * 2026-09-25: 3809 MB, MemAvailable 2428, engine containers 481, no projects.
 */
class HostMemoryTest extends TestCase
{
    private const ENGINE_DIR = '/opt/panelalpha/shared-hosting';

    private function mariusz2(int $projectsMb = 0, int $availableMb = 2428, ?int $pool = null): HostMemory
    {
        return new HostMemory(3809, $availableMb, 481, $projectsMb, $pool);
    }

    public function test_every_mb_is_engine_system_projects_or_headroom(): void
    {
        $memory = $this->mariusz2();

        $this->assertSame(900, $memory->systemMb(), 'used 1381 minus engine 481');
        $this->assertSame(2172, $memory->projectsPoolMb(), '3809 - 481 - 900 - 256');
        $this->assertSame(2172, $memory->maxProjectMb());
        $this->assertSame(2172, $memory->freeForProjectsMb());
    }

    /** What projects use is taken from the pool, not from the system. */
    public function test_running_projects_use_up_the_pool_but_not_the_maximum(): void
    {
        $memory = $this->mariusz2(projectsMb: 1000, availableMb: 1428);

        $this->assertSame(900, $memory->systemMb());
        $this->assertSame(2172, $memory->maxProjectMb());
        $this->assertSame(1172, $memory->freeForProjectsMb());
    }

    /** 16 GB for projects on a 32 GB host, whatever else is free. */
    public function test_a_configured_pool_caps_projects_together(): void
    {
        $host = new HostMemory(32000, 28000, 500, 12000, 16384);

        $this->assertSame(16384, $host->maxProjectMb());
        $this->assertSame(4384, $host->freeForProjectsMb(), 'the pool runs out before the host does');
        $this->assertSame('configured', $host->toArray()['projects_pool_source']);
    }

    /** A pool larger than the host does not make memory appear. */
    public function test_free_never_exceeds_what_the_host_has(): void
    {
        $this->assertSame(1744, (new HostMemory(3809, 2000, 481, 0, 16384))->freeForProjectsMb());
    }

    public function test_it_reads_the_host_and_splits_engine_from_projects(): void
    {
        $meminfo = "MemTotal:        3900696 kB\nMemFree:          100000 kB\nMemAvailable:    2486272 kB\n";
        $ps = implode("\n", [
            "aaaa\t" . self::ENGINE_DIR,
            "bbbb\t" . self::ENGINE_DIR,
            "cccc\t/opt/panelalpha/shared-hosting/users/shop",
            "dddd\t",
        ]);
        $cgroups = implode("\n", ["aaaa\t314572800", "bbbb\t52428800", "cccc\t1073741824", "dddd\t10485760", "eeee\tjunk"]);

        $memory = HostMemoryProbe::fromReadings($meminfo, $ps, $cgroups, self::ENGINE_DIR);

        $this->assertSame([3809, 2428, 350, 1034], [
            $memory->totalMb, $memory->availableMb, $memory->engineMb, $memory->projectsMb,
        ]);
    }

    public function test_a_project_without_a_limit_gets_the_default(): void
    {
        $this->assertSame(2048, ProjectMemory::defaultMb());
        $this->assertSame(2048, ProjectMemory::resolve(null));
        $this->assertSame(2048, ProjectMemory::resolve(0));
        $this->assertSame(512, ProjectMemory::resolve(512));
    }

    /** A project from before limits were required is sized like one created with the default. */
    public function test_a_project_without_a_limit_sizes_its_services_and_build_from_the_default(): void
    {
        $mb = ProjectMemory::resolve(null);
        $host15g = "MemTotal:       15983292 kB\n";

        $this->assertSame('1792m', ServiceLimits::memoryFor('app', [], $mb));
        $this->assertSame(1433, ServiceLimits::nodeHeapMbForAccount($mb));
        $this->assertSame(BuildMemory::SERVER, DindEngine::buildMemory('', $host15g, $mb)->source);
    }

    public function test_creation_needs_the_limit_free_now(): void
    {
        $busy = $this->mariusz2(projectsMb: 1000, availableMb: 1428);

        $this->assertNull(ProjectMemory::creationProblem(1024, $busy));
        $problem = ProjectMemory::creationProblem(2048, $busy);
        $this->assertSame('not_enough_memory', $problem['code'] ?? null);
        $this->assertSame(1172, $problem['free_mb'] ?? null);
        $this->assertStringContainsString('1172 MB free', $problem['message'] ?? '');
    }

    public function test_no_project_may_exceed_the_maximum(): void
    {
        $memory = $this->mariusz2();

        $this->assertSame('memory_limit_too_large', ProjectMemory::creationProblem(4096, $memory)['code'] ?? null);
        $this->assertSame('memory_limit_too_large', ProjectMemory::changeProblem(4096, $memory)['code'] ?? null);
        // Changing a limit is not refused for memory in use, only for the maximum.
        $this->assertNull(ProjectMemory::changeProblem(2048, $this->mariusz2(projectsMb: 1000, availableMb: 1428)));
    }

    /** No floor of ours: a tiny static site may run on very little (#296 is why it cannot go lower). */
    public function test_any_positive_limit_that_fits_is_accepted(): void
    {
        $this->assertNull(ProjectMemory::creationProblem(32, $this->mariusz2()));
        $this->assertNull(ProjectMemory::changeProblem(32, $this->mariusz2()));
    }

    public function test_the_rule_reports_the_problem_with_its_code(): void
    {
        $rule = new AccountMemoryLimit(true, $this->mariusz2(projectsMb: 1000, availableMb: 1428));
        $failed = null;
        $rule->validate('memory_limit', 2048, function (string $m) use (&$failed) {
            $failed = $m;
        });

        $this->assertNotNull($failed);
        $this->assertSame('not_enough_memory', $rule->problem()['code'] ?? null);
        $this->assertSame('memory_limit', $rule->problem()['field'] ?? null);
    }
}
