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
}
