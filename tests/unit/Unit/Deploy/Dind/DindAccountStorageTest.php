<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindAccountStorage;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;

/**
 * Disk probes and store removal run inside the account's container, whose hostname
 * sysbox sets to the username, so the data root is `$(hostname)` rather than templated.
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

    /** It runs during a deploy, so an account with no store yet reports "unknown" rather than exiting non-zero. */
    public function test_a_probe_that_fails_does_not_fail_the_command(): void
    {
        $argv = $this->storage->freeSpaceProbeArgv();

        $this->assertStringContainsString('|| true', end($argv));
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

    public function test_the_wipe_script_is_shell_the_account_can_run(): void
    {
        $this->assertNotSame('', trim($this->storage->fullWipeScript()));
    }

    public function test_stopping_the_engine_uses_the_accounts_own_init(): void
    {
        // No systemd inside the account container.
        $this->assertSame(['service', 'docker', 'stop'], $this->storage->stopEngineArgv());
    }

    public function test_host_cleanup_is_scoped_to_the_account(): void
    {
        $account = new EngineAccount('acme', '/home/acme');

        $argvs = $this->storage->hostCleanupArgvs($account);

        $this->assertCount(2, $argvs);
        $this->assertStringContainsString('acme', (string) json_encode($argvs));
    }
}
