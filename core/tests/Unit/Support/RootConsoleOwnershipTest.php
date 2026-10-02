<?php

namespace Tests\Unit\Support;

use App\Support\RootConsoleOwnership;
use Tests\TestCase;

class RootConsoleOwnershipTest extends TestCase
{
    public function test_only_a_root_console_run_outside_tests_repairs(): void
    {
        $this->assertTrue(RootConsoleOwnership::applies(true, 0, false));
        $this->assertFalse(RootConsoleOwnership::applies(true, 33, false), 'www-data already owns what it writes');
        $this->assertFalse(RootConsoleOwnership::applies(false, 0, false), 'php-fpm never runs as root');
        $this->assertFalse(RootConsoleOwnership::applies(true, 0, true), 'a root test run must not chown the checkout');
    }

    public function test_repair_hands_root_owned_entries_to_the_owner_without_following_links(): void
    {
        $dir = sys_get_temp_dir() . '/pa-root-own-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $this->assertSame(
                ['find', '-H', $dir, '-user', '0', '-exec', 'chown', '-h', '33:33', '{}', '+'],
                RootConsoleOwnership::repairArgv([$dir, $dir . '/missing'], ['uid' => 33, 'gid' => 33])
            );
        } finally {
            rmdir($dir);
        }
    }

    public function test_nothing_runs_without_the_user_or_any_path(): void
    {
        $this->assertNull(RootConsoleOwnership::repairArgv([sys_get_temp_dir()], null));
        $this->assertNull(RootConsoleOwnership::repairArgv(['/nonexistent-pa-path'], ['uid' => 33, 'gid' => 33]));
    }
}
