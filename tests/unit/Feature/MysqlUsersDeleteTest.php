<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\MysqlPrivilegesTest::test_delete_mysql_user_privileges
 */
class MysqlUsersDeleteTest extends TestCase
{
    #[UnsetsCache('mysql_user')]
    public function test_delete_mysql_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $this->authenticate();
        
        $response = $this->deleteJson("/api/users/{$username}/mysql/users/" . $mysqlUsername, []);
        $response->assertStatus(200);

        $this->unsetCache('mysql_user');
    }
}