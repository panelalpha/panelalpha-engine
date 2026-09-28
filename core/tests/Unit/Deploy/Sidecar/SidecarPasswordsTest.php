<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\SidecarPasswords;
use PHPUnit\Framework\TestCase;

/**
 * engine#189: a database sidecar nobody gave a password used to get `app`, for
 * the user and root alike.
 */
class SidecarPasswordsTest extends TestCase
{
    public function test_a_derived_password_is_not_app_and_differs_per_variable_and_account(): void
    {
        $a = SidecarPasswords::derived('seed-a');
        $b = SidecarPasswords::derived('seed-b');

        $this->assertNotSame('app', $a->for('MYSQL_PASSWORD'));
        $this->assertSame(32, strlen($a->for('MYSQL_PASSWORD')));
        $this->assertSame($a->for('MYSQL_PASSWORD'), $a->for('mysql_password'));
        $this->assertNotSame($a->for('MYSQL_PASSWORD'), $a->for('MYSQL_ROOT_PASSWORD'));
        $this->assertNotSame($a->for('MYSQL_PASSWORD'), $b->for('MYSQL_PASSWORD'));
        $this->assertNotSame('s3cret', $a->mysqlRoot('s3cret'));
    }

    public function test_legacy_keeps_app_and_roots_shared_password(): void
    {
        $legacy = SidecarPasswords::legacy();

        $this->assertTrue($legacy->isLegacy());
        $this->assertSame('app', $legacy->for('POSTGRES_PASSWORD'));
        $this->assertSame('s3cret', $legacy->mysqlRoot('s3cret'));
    }

    public function test_an_account_that_already_holds_volumes_is_legacy_and_that_is_stored(): void
    {
        $this->assertSame(['mode' => 'legacy', 'store' => true], SidecarPasswords::decideMode(null, true));
        $this->assertSame(['mode' => 'derived', 'store' => true], SidecarPasswords::decideMode(null, false));
    }

    public function test_a_stored_mode_wins_over_what_the_volumes_say_now(): void
    {
        // After a first derived deploy the account has a volume; asking again
        // would flip it to legacy and lock the app out.
        $this->assertSame(['mode' => 'derived', 'store' => false], SidecarPasswords::decideMode('derived', true));
        $this->assertSame(['mode' => 'legacy', 'store' => false], SidecarPasswords::decideMode('legacy', false));
    }

    public function test_volumes_that_cannot_be_listed_mean_legacy_for_now_and_ask_again(): void
    {
        $this->assertSame(['mode' => 'legacy', 'store' => false], SidecarPasswords::decideMode(null, null));
        $this->assertSame(['mode' => 'legacy', 'store' => false], SidecarPasswords::decideMode('garbage', null));
    }
}
