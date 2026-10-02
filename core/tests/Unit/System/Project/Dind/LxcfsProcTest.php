<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind\LxcfsProc;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * An account read the host's /proc/meminfo, loadavg and cpuinfo: every tenant
 * saw the host's RAM and load, and a monitoring app reported them as its own.
 */
class LxcfsProcTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    /** @param list<string> $procMounts */
    private function renderedVolumes(array $procMounts): array
    {
        $yaml = Blade::render((string) file_get_contents(self::TEMPLATE), [
            'user' => 'alice',
            'isolation' => 'runtime: sysbox-runc',
            'cpu_limit' => '',
            'memory_limit' => '512',
            'device_read_bps' => null,
            'device_write_bps' => null,
            'block_device' => '',
            'proc_mounts' => $procMounts,
        ]);

        return Yaml::parse($yaml)['services']['dind']['volumes'] ?? [];
    }

    private function hostAnswering(string $output): System
    {
        $system = $this->createStub(System::class);
        $system->method('execOnHost')->willReturn($output);

        return $system;
    }

    public function test_the_account_gets_read_only_lxcfs_files_over_its_proc(): void
    {
        $volumes = $this->renderedVolumes(LxcfsProc::mounts(LxcfsProc::FILES, true));

        $binds = array_values(array_filter($volumes, 'is_array'));
        $this->assertSame(
            ['/proc/meminfo', '/proc/cpuinfo', '/proc/stat', '/proc/loadavg', '/proc/diskstats'],
            array_column($binds, 'target')
        );
        foreach ($binds as $bind) {
            $this->assertSame('bind', $bind['type']);
            $this->assertSame(LxcfsProc::PROC_DIR . '/' . basename($bind['target']), $bind['source']);
            $this->assertTrue($bind['read_only']);
        }
        // The account's own mounts are still there.
        $this->assertContains('./services/:/etc/s6/account/:ro', $volumes);
    }

    /** sysbox-fs already serves these two in the account; lxcfs would stack a second FUSE layer. */
    public function test_sysbox_keeps_its_own_uptime_and_swaps(): void
    {
        $mounts = LxcfsProc::mounts(LxcfsProc::FILES, true);

        $this->assertNotContains('uptime', $mounts);
        $this->assertNotContains('swaps', $mounts);
    }

    public function test_a_privileged_account_gets_every_file(): void
    {
        $this->assertSame(LxcfsProc::FILES, LxcfsProc::mounts(LxcfsProc::FILES, false));
    }

    public function test_only_what_lxcfs_provides_is_mounted(): void
    {
        $this->assertSame(['meminfo', 'loadavg'], LxcfsProc::mounts(['loadavg', 'meminfo'], true));
    }

    public function test_no_lxcfs_on_the_host_mounts_nothing(): void
    {
        $present = LxcfsProc::present($this->hostAnswering(''));

        $this->assertSame([], $present);
        $this->assertSame([], LxcfsProc::mounts($present, true));
        $this->assertSame(['./services/:/etc/s6/account/:ro'], array_slice($this->renderedVolumes([]), -1));
    }

    public function test_it_reads_which_files_the_host_serves(): void
    {
        $this->assertSame(
            ['meminfo', 'cpuinfo', 'loadavg'],
            LxcfsProc::present($this->hostAnswering("meminfo\ncpuinfo\nloadavg\nsomething-else\n"))
        );
    }

    public function test_a_host_that_cannot_be_asked_mounts_nothing(): void
    {
        $system = $this->createStub(System::class);
        $system->method('execOnHost')->willThrowException(new \RuntimeException('nsenter failed'));

        $this->assertSame([], LxcfsProc::present($system));
    }
}
