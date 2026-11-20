<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformStage;
use PHPUnit\Framework\TestCase;

/**
 * The stages run on the host and in the running container, each in the order they run.
 */
class PlatformStageOrderTest extends TestCase
{
    public function test_host_and_runtime_stages_are_separate_ordered_lists(): void
    {
        $host = PlatformStage::hostStages();
        $runtime = PlatformStage::runtimeStages();

        $this->assertNotSame([], $host);
        $this->assertNotSame([], $runtime);
        $this->assertSame([], array_intersect($host, $runtime));
        $this->assertSame(array_values(array_unique($host)), $host);
        $this->assertSame(array_values(array_unique($runtime)), $runtime);
    }
}
