<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * `docker stats --no-stream --format '{{.CPUPerc}}'`, as measured: a spinning
 * container printed `102.15%`, an idle one `0.00%`.
 */
class StepBusyCpuTest extends TestCase
{
    public function test_docker_stats_percentages_are_summed(): void
    {
        $this->assertSame(102.15, ShellOperations::cpuPercent("102.15%\n"));
        $this->assertSame(0.0, ShellOperations::cpuPercent("0.00%\n"));
        $this->assertEqualsWithDelta(52.5, ShellOperations::cpuPercent("50.00%\n2.50%\n"), 0.001);
    }

    public function test_anything_else_reads_as_idle(): void
    {
        $this->assertSame(0.0, ShellOperations::cpuPercent(''));
        $this->assertSame(0.0, ShellOperations::cpuPercent("--\n"));
        $this->assertSame(0.0, ShellOperations::cpuPercent("no such service: dind\n"));
    }

    /**
     * Two `<uptime> <usage_usec>` samples of the account's cgroup, as read on
     * a dev host with a BuildKit RUN step spinning while `docker stats` said
     * 0.97%, then idle.
     */
    public function test_cgroup_samples_give_the_cpu_of_nested_builds(): void
    {
        $this->assertEqualsWithDelta(201.7, ShellOperations::cgroupCpuPercent("231.41 353554173\n233.41 357587418\n"), 0.1);
        $this->assertEqualsWithDelta(0.5, ShellOperations::cgroupCpuPercent("289.19 395156634\n291.20 395166770\n"), 0.01);
    }

    /** Anything unreadable is null, and the probe falls back to `docker stats`. */
    public function test_unreadable_cgroup_samples_are_null(): void
    {
        $this->assertNull(ShellOperations::cgroupCpuPercent(''));
        $this->assertNull(ShellOperations::cgroupCpuPercent(" 222677606\n 226703398\n"));
        $this->assertNull(ShellOperations::cgroupCpuPercent("231.41 353554173\n"));
        $this->assertNull(ShellOperations::cgroupCpuPercent("231.41 353554173\n231.41 357587418\n"));
        $this->assertNull(ShellOperations::cgroupCpuPercent("cut: /proc/uptime: No such file or directory\n"));
    }
}
