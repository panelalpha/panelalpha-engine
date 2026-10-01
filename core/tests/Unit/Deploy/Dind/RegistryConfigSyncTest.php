<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\RegistryConfigSync;
use PHPUnit\Framework\TestCase;

/**
 * engine#312: a registry-settings refresh no longer runs anything inside the
 * account. These are the two facts it needs from the host's own view of the
 * account's container before it may rewrite the host file and signal it.
 */
class RegistryConfigSyncTest extends TestCase
{
    public function test_an_account_created_after_the_mount_shipped_is_recognised(): void
    {
        $mounts = '[{"Type":"bind","Source":"/home/demo/project/daemon.json","Destination":"/etc/docker/daemon.json","Mode":"ro"}]';

        $this->assertTrue(RegistryConfigSync::hasDaemonJsonMount($mounts));
    }

    public function test_an_account_that_predates_the_mount_is_left_alone(): void
    {
        // Only its own volumes and the usual entrypoint mounts -- no daemon.json.
        $mounts = '[{"Type":"bind","Source":"/home/demo","Destination":"/home/demo","Mode":""},'
            . '{"Type":"bind","Source":"/opt/.../entrypoint.sh","Destination":"/entrypoint.sh","Mode":""}]';

        $this->assertFalse(RegistryConfigSync::hasDaemonJsonMount($mounts));
    }

    public function test_a_mount_at_a_different_destination_does_not_count(): void
    {
        $mounts = '[{"Type":"bind","Source":"/home/demo/project/daemon.json","Destination":"/tmp/daemon.json","Mode":"ro"}]';

        $this->assertFalse(RegistryConfigSync::hasDaemonJsonMount($mounts));
    }

    public function test_unparseable_inspect_output_is_treated_as_unmounted(): void
    {
        $this->assertFalse(RegistryConfigSync::hasDaemonJsonMount(''));
        $this->assertFalse(RegistryConfigSync::hasDaemonJsonMount('not json'));
        $this->assertFalse(RegistryConfigSync::hasDaemonJsonMount('{"not":"a list"}'));
    }

    public function test_dockerd_pid_is_read_from_docker_top(): void
    {
        $top = "PID    COMM\n     1   s6-svscan\n    42   dockerd\n    99   containerd-shim\n";

        $this->assertSame(42, RegistryConfigSync::dockerdHostPid($top));
    }

    public function test_no_dockerd_line_means_no_pid(): void
    {
        $top = "PID    COMM\n     1   s6-svscan\n";

        $this->assertNull(RegistryConfigSync::dockerdHostPid($top));
    }

    public function test_empty_output_means_no_pid(): void
    {
        $this->assertNull(RegistryConfigSync::dockerdHostPid(''));
    }
}
