<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindAccountStorage;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;

/**
 * How the engine asks a Docker-in-Docker account about its disk, and how it
 * reclaims space there.
 *
 * The commands run inside the account's own container with its own daemon,
 * which is what makes the shapes here worth pinning: `docker ps -aq` rather
 * than `docker ps`, because a stopped container still owns layers a prune
 * would take; a `$(hostname)` data root rather than a templated path, because
 * sysbox gives each account container its username as hostname.
 */
class DindAccountStorageTest extends TestCase
{
    private DindAccountStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new DindAccountStorage();
    }

    public function test_the_data_root_resolves_per_account_without_templating(): void
    {
        // sysbox sets the container's hostname to the account's username, so
        // one expression is correct in every account.
        $this->assertSame('/home/$(hostname)/docker', $this->storage->dataRoot());
    }

    public function test_the_free_space_probe_looks_at_the_accounts_own_data_root(): void
    {
        $argv = $this->storage->freeSpaceProbeArgv();

        $this->assertStringContainsString($this->storage->dataRoot(), end($argv));
    }

    public function test_a_probe_that_fails_does_not_fail_the_command(): void
    {
        // These run during a deploy. An account whose daemon is not up yet
        // must report "unknown", not abort the deploy with a non-zero exit.
        foreach ([
            $this->storage->freeSpaceProbeArgv(),
            $this->storage->buildCacheProbeArgv(),
            $this->storage->containersProbeArgv(),
        ] as $argv) {
            $this->assertStringContainsString('|| true', end($argv));
        }
    }

    public function test_free_space_is_read_from_df(): void
    {
        $output = "Filesystem     1024-blocks     Used Available Capacity Mounted on\n"
            . "/dev/sda1        103080888 62038900  41041988      61% /\n";

        $this->assertSame(41041988 * 1024, $this->storage->parseFreeBytes($output));
    }

    public function test_an_unreadable_df_reports_nothing_rather_than_zero(): void
    {
        // Zero would read as "full" and trigger a wipe of an account that is
        // fine.
        $this->assertNull($this->storage->parseFreeBytes(''));
        $this->assertNull($this->storage->parseFreeBytes("df: /home/x/docker: No such file or directory\n"));
    }

    public function test_the_build_cache_size_is_read_from_buildx(): void
    {
        $output = "ID    RECLAIMABLE  SIZE      LAST ACCESSED\n"
            . "abc   true         1.234GB   2 hours ago\n"
            . "Total:\t2.5GB\n";

        $this->assertGreaterThan(0, $this->storage->parseBuildCacheBytes($output));
    }

    public function test_an_unreadable_buildx_reports_nothing(): void
    {
        $this->assertNull($this->storage->parseBuildCacheBytes(''));
    }

    public function test_stopped_containers_count_when_deciding_whether_to_prune(): void
    {
        // `-a`: a stopped container still owns layers a prune would take, so
        // its presence is what makes an aggressive reclaim unsafe.
        $argv = $this->storage->containersProbeArgv();

        $this->assertStringContainsString('docker ps -aq', end($argv));
    }

    public function test_the_full_prune_takes_volumes_too(): void
    {
        // Which is why it is the last resort: it is the account's data.
        $this->assertSame(
            ['docker', 'system', 'prune', '-af', '--volumes'],
            $this->storage->pruneAllArgv()
        );
    }

    public function test_the_build_cache_prune_covers_both_builders(): void
    {
        // An account may have layers under the legacy builder and under
        // buildx; pruning one leaves the other's disk usage in place.
        $argvs = $this->storage->pruneBuildCacheArgv();

        $this->assertContains(['docker', 'builder', 'prune', '-af'], $argvs);
        $this->assertContains(['docker', 'buildx', 'prune', '-af'], $argvs);
    }

    public function test_the_build_cache_prune_never_touches_volumes(): void
    {
        // The recoverable step. Losing a build cache costs time; losing a
        // volume costs the customer's data.
        foreach ($this->storage->pruneBuildCacheArgv() as $argv) {
            $this->assertNotContains('--volumes', $argv);
        }
    }

    public function test_the_wipe_script_is_shell_the_account_can_run(): void
    {
        $this->assertNotSame('', trim($this->storage->fullWipeScript()));
    }

    /**
     * Through dockerd, which keeps running: deleting containerd's directories
     * under it would need it stopped. Volumes are the account's data and stay.
     */
    public function test_the_reclaim_prunes_images_and_both_build_caches_but_no_volumes(): void
    {
        $argvs = $this->storage->reclaimArgvs();

        $this->assertSame(['docker', 'system', 'prune', '-af'], $argvs[0]);
        $this->assertContains(['docker', 'builder', 'prune', '-af'], $argvs);
        $this->assertContains(['docker', 'buildx', 'prune', '-af'], $argvs);
        foreach ($argvs as $argv) {
            $this->assertNotContains('--volumes', $argv);
            $this->assertNotContains('rm', $argv);
        }
    }

    public function test_stopping_the_engine_uses_the_accounts_own_init(): void
    {
        // No systemd inside the account container.
        $this->assertSame(['service', 'docker', 'stop'], $this->storage->stopEngineArgv());
    }

    public function test_host_cleanup_is_scoped_to_the_account(): void
    {
        $account = new EngineAccount('acme', '/home/acme');

        $argv = $this->storage->hostCleanupArgv($account);

        $this->assertStringContainsString('acme', (string) json_encode($argv));
    }
}
