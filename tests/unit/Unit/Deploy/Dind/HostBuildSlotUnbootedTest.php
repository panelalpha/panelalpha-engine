<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\HostBuildSlot;
use PHPUnit\Framework\TestCase;

/**
 * Outside a booted application there is no storage to hold the slot in, so a build runs unslotted.
 */
class HostBuildSlotUnbootedTest extends TestCase
{
    public function test_a_build_runs_without_a_slot_and_returns_its_result(): void
    {
        $waited = false;

        $result = HostBuildSlot::run(static fn (): string => 'built', static function () use (&$waited): void {
            $waited = true;
        });

        $this->assertSame('built', $result);
        $this->assertFalse($waited);
    }
}
