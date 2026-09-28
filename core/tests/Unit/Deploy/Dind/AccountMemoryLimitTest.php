<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Dind\DindEngine;
use PHPUnit\Framework\TestCase;

/**
 * An account created without memory_limit used to run with no cgroup cap at
 * all, so one tenant's build could OOM the host and core with it (engine#110).
 */
class AccountMemoryLimitTest extends TestCase
{
    private const HOST_15G = "MemTotal:       15983292 kB\nMemFree: 1 kB\n";

    public function test_unset_plan_limit_gets_half_the_host(): void
    {
        $this->assertSame(7804, DindEngine::resolveAccountMemoryMb(null, '', self::HOST_15G));
    }

    public function test_small_host_is_floored_at_2g(): void
    {
        $this->assertSame(2048, DindEngine::resolveAccountMemoryMb(null, '', "MemTotal: 2097152 kB\n"));
    }

    public function test_unreadable_meminfo_falls_back_to_the_floor(): void
    {
        $this->assertSame(2048, DindEngine::resolveAccountMemoryMb(null, '', ''));
        $this->assertSame(2048, ServiceLimits::accountDefaultMemoryMb('garbage'));
    }

    public function test_plan_limit_wins(): void
    {
        $this->assertSame(2500, DindEngine::resolveAccountMemoryMb(2500, '6g', self::HOST_15G));
    }

    public function test_plan_limit_zero_stays_unlimited(): void
    {
        $this->assertNull(DindEngine::resolveAccountMemoryMb(0, '', self::HOST_15G));
    }

    public function test_operator_default_overrides_the_host_share(): void
    {
        $this->assertSame(6144, DindEngine::resolveAccountMemoryMb(null, '6g', self::HOST_15G));
        $this->assertSame(4096, DindEngine::resolveAccountMemoryMb(null, '4096', self::HOST_15G));
    }

    public function test_operator_zero_opts_back_out(): void
    {
        $this->assertNull(DindEngine::resolveAccountMemoryMb(null, '0', self::HOST_15G));
    }

    public function test_unparseable_operator_value_falls_back_to_the_host_share(): void
    {
        $this->assertSame(7804, DindEngine::resolveAccountMemoryMb(null, 'lots', self::HOST_15G));
    }
}
