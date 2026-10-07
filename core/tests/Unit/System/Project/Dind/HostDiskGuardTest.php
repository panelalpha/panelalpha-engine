<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\System;
use App\System\Project\Dind\HostDiskGuard;
use PHPUnit\Framework\TestCase;

/**
 * The pre-deploy host disk check: a nearly full host refuses the
 * deploy by name instead of failing it later on ENOSPC.
 */
class HostDiskGuardTest extends TestCase
{
    public function test_refuses_when_either_path_is_under_the_minimum(): void
    {
        $guard = new HostDiskGuard($this->host(['/var/lib/docker' => 50, '/home' => 2 * 1048576]), 3 * 1073741824);

        $refusal = $guard->refusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('50M free on /var/lib/docker', $refusal);
        $this->assertStringContainsString('below the 3G', $refusal);
        $this->assertStringContainsString('DEPLOY_HOST_MIN_FREE', $refusal);
    }

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

    public function test_the_refusal_is_not_rewritten_as_the_accounts_disk_being_full(): void
    {
        $refusal = (new HostDiskGuard($this->host(['/home' => 100]), 3 * 1073741824))->refusal();

        $this->assertNotNull($refusal);
        $this->assertNull(DeployFailureExplainer::match($refusal));
    }

    /**
     * @param array<string, int> $freeMb free space per path in MB; a missing path fails df
     */
    private function host(array $freeMb): System
    {
        return new class ($freeMb) extends System {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string, int> $freeMb */
            public function __construct(private array $freeMb)
            {
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $this->asked[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $path = is_array($cmd) ? (string) end($cmd) : '';
                if (!isset($this->freeMb[$path])) {
                    throw new \RuntimeException("df: {$path}: No such file or directory");
                }

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . '/dev/sda1 157286400 1000 ' . ($this->freeMb[$path] * 1024) . " 30% {$path}\n";
            }
        };
    }
}
