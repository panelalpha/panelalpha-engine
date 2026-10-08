<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\Attributes\UpdatesCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class MysqlUsersTest extends TestCase
{
    #[SetsCache('mysql_user')]
    public function test_create_mysql_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $mysqlUserPayload = [
            'name' => 'user' . Str::random(5),
            'password' => 'SecurePass123'
        ];

        $response = $this->postJson("/api/users/{$username}/mysql/users", $mysqlUserPayload);
        $response->assertStatus(201);
        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('mysql_user', $result);
    }

    public function test_get_mysql_users(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/mysql/users");
        $response->assertStatus(200);
    }

    public function test_show_mysql_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/mysql/users/" . $mysqlUsername);
        $response->assertStatus(200);
    }

    #[UpdatesCache('mysql_user')]
    public function test_rename_mysql_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $this->authenticate();

        $renamePayload = ['name' => 'renamed' . Str::random(5)];
        $response = $this->putJson("/api/users/{$username}/mysql/users/" . $mysqlUsername . "/rename", $renamePayload);
        $response->assertStatus(200);

        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('mysql_user', $result);
    }

    #[UpdatesCache('mysql_user')]
    public function test_change_mysql_user_password(): void
    {
        $username = $this->getCacheAsString('user.username');
        $mysqlUsername = $this->getCacheAsString('mysql_user.user');
        $this->authenticate();

        $passwordPayload = ['password' => 'NewSecurePass123'];
        $response = $this->putJson("/api/users/{$username}/mysql/users/" . $mysqlUsername . "/change-password", $passwordPayload);
        $response->assertStatus(200);

        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('mysql_user', $result);
    }
}
