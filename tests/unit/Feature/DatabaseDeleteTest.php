<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\MysqlPrivilegesTest::test_delete_mysql_user_privileges
 * @depends Tests\Feature\MysqlValidationTest::test_rejects_duplicate_database_name
 */
class DatabaseDeleteTest extends TestCase
{
    #[UnsetsCache('database')]
    public function test_delete_database(): void
    {
        $username = $this->getCacheAsString('user.username');
        $databaseName = $this->getCacheAsString('database.database');
        $this->authenticate();
        
        $response = $this->deleteJson("/api/users/{$username}/mysql/databases/{$databaseName}");
        $response->assertStatus(200);

        $this->unsetCache('database');
    }
}
