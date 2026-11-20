<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind\HostDiskGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostDiskGuardFixtures;

/**
 * The pre-deploy host disk check: a nearly full host refuses the
 * deploy by name instead of failing it later on ENOSPC.
 */
class HostDiskGuardTest extends TestCase
{
    use HostDiskGuardFixtures;

    public function test_goes_ahead_with_room_on_both_paths(): void
    {
        $guard = new HostDiskGuard($this->host(['/var/lib/docker' => 20 * 1024, '/home' => 20 * 1024]), 3 * 1073741824);

        $this->assertNull($guard->refusal());
    }

    public function test_an_unreadable_disk_is_not_a_refusal(): void
    {
        $guard = new HostDiskGuard($this->host([]), 3 * 1073741824);

        $this->assertNull($guard->refusal());
    }

    /** A host the broker cannot enter was never read, so the check must not pass as if it had room. */
    public function test_a_host_namespace_refusal_is_not_an_unreadable_disk(): void
    {
        $system = new class extends System {
            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('pa-root: cannot enter the host\'s namespaces: Operation not permitted');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pa-root: cannot enter the host\'s namespaces: Operation not permitted');
        (new HostDiskGuard($system, 3 * 1073741824))->refusal();
    }

    public function test_off_never_asks_the_host(): void
    {
        $host = $this->host(['/home' => 1]);
        $guard = new HostDiskGuard($host, HostDiskGuard::minimumFrom('off'));

        $this->assertNull($guard->refusal());
        $this->assertSame([], $host->asked);
    }

    public function test_minimum_from_config(): void
    {
        $this->assertSame(3 * 1073741824, HostDiskGuard::minimumFrom(null));
        $this->assertSame(3 * 1073741824, HostDiskGuard::minimumFrom(''));
        $this->assertSame(3 * 1073741824, HostDiskGuard::minimumFrom('lots'));
        $this->assertSame(512 * 1048576, HostDiskGuard::minimumFrom('512M'));
        $this->assertNull(HostDiskGuard::minimumFrom('0'));
        $this->assertNull(HostDiskGuard::minimumFrom('OFF'));
    }
}
