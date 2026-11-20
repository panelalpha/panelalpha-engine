<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeInterpolation;
use App\Lib\Deploy\Compose\MountSourcePolicy;
use PHPUnit\Framework\TestCase;

/** Values whose outcomes cannot be listed: too many combinations, or a value that itself interpolates. */
class ComposeInterpolationLimitsTest extends TestCase
{
    public function test_too_many_combinations_are_unknowable(): void
    {
        $five = ['a', 'b', 'c', 'd', 'e'];

        $this->assertCount(25, ComposeInterpolation::candidates('${A}${B}', ['A' => $five, 'B' => $five]));
        $this->assertNull(ComposeInterpolation::candidates('${A}${B}${C}', ['A' => $five, 'B' => $five, 'C' => $five]));
    }

    public function test_a_value_that_holds_a_reference_itself_is_unknowable(): void
    {
        $this->assertNull(ComposeInterpolation::candidates('${DATA_DIR}/x', ['DATA_DIR' => '$HOME/data']));
    }

    public function test_a_default_that_reads_the_process_environment_is_unknowable(): void
    {
        $this->assertNull(ComposeInterpolation::candidates('${DATA_DIR:-$HOME/data}', []));
        $this->assertSame(['/srv/data'], ComposeInterpolation::candidates('${DATA_DIR:-$HOME/data}', ['DATA_DIR' => '/srv/data']));
    }

    public function test_an_unknowable_source_is_refused_as_a_mount(): void
    {
        $this->assertTrue(MountSourcePolicy::isForbiddenSourceIn('${DATA_DIR}/x', ['DATA_DIR' => '$HOME/data']));
        $this->assertFalse(MountSourcePolicy::isForbiddenSourceIn('${DATA_DIR:-./data}', []));
    }
}
