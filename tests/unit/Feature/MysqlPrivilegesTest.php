<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\MysqlUsersTest::test_create_mysql_user
 * @depends Tests\Feature\DatabaseTest::test_create_database
 */
class MysqlPrivilegesTest extends TestCase
{
    public function test_get_mysql_user_privileges(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $databaseName = $this->getCacheAsString('database.database');
        $this->authenticate();

        $privilegesPayload = ['privileges' => 'SELECT,INSERT,UPDATE'];
        $assignPrivilegesResponse = $this->putJson("/api/users/{$username}/mysql/privileges/" . $mysqlUsername . "/" . $databaseName, $privilegesPayload);

        $assignPrivilegesResponse->assertStatus(200);

        $response = $this->getJson("/api/users/{$username}/mysql/privileges/" . $mysqlUsername . "/" . $databaseName);

        $response->assertStatus(200);
    }

    public function test_update_mysql_user_privileges(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $databaseName = $this->getCacheAsString('database.database');
        $this->authenticate();

        $privilegesPayload = ['privileges' => 'SELECT,INSERT,UPDATE'];
        $response = $this->putJson("/api/users/{$username}/mysql/privileges/" . $mysqlUsername . "/" . $databaseName, $privilegesPayload);
        $response->assertStatus(200);
    }

    public function test_delete_mysql_user_privileges(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $databaseName = $this->getCacheAsString('database.database');
        $this->authenticate();

        $response = $this->deleteJson("/api/users/{$username}/mysql/privileges/" . $mysqlUsername . "/" . $databaseName, []);
        $response->assertStatus(200);
    }
}
