<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * mysql_user_create/mysql_database_create add the account's prefix when a
 * caller sends a short name, but the stored value -- and every other MySQL
 * endpoint's lookup -- is always the full prefixed name. Before this fix,
 * mysql_privileges_get/set, mysql_user_change_password and their siblings
 * 404'd on the short name, undocumented in their MCP schemas.
 */
class UserMysqlQualifyTest extends TestCase
{
    private function userWithPrefix(string $prefix): User
    {
        $user = new User();
        $user->setDetails(['mysql_prefix' => $prefix]);

        return $user;
    }

    public function test_a_short_name_gets_the_prefix_added(): void
    {
        $user = $this->userWithPrefix('shop_');

        $this->assertSame('shop_dbuser1', $user->qualifyMysqlUser('dbuser1'));
        $this->assertSame('shop_reports', $user->qualifyMysqlDatabase('reports'));
    }

    public function test_an_already_prefixed_name_is_untouched(): void
    {
        $user = $this->userWithPrefix('shop_');

        $this->assertSame('shop_dbuser1', $user->qualifyMysqlUser('shop_dbuser1'));
        $this->assertSame('shop_reports', $user->qualifyMysqlDatabase('shop_reports'));
    }

    public function test_no_prefix_configured_leaves_the_name_as_is(): void
    {
        $user = $this->userWithPrefix('');

        $this->assertSame('dbuser1', $user->qualifyMysqlUser('dbuser1'));
    }
}
