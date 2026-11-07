<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeOverride;
use PHPUnit\Framework\TestCase;

class ComposeOverrideNonMapServiceTest extends TestCase
{
    public function test_a_service_that_is_not_a_map_is_left_as_written(): void
    {
        $this->assertSame(
            ['yaml' => "services:\n  a: null\n  b:\n    image: x\n", 'removed' => ['b: privileged']],
            ComposeOverride::harden("services:\n  a: ~\n  b:\n    image: x\n    privileged: true\n")
        );
    }
}
