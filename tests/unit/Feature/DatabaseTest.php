<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class DatabaseTest extends TestCase
{
    public function test_get_user_databases(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/mysql/databases");
        $response->assertStatus(200);
    }

    #[SetsCache('database')]
    public function test_create_database(): void
    {
        $this->skipIfCached('database');
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        
        $dbPayload = [
            'name' => 'db' . Str::random(5)
        ];
        
        $response = $this->postJson("/api/users/{$username}/mysql/databases", $dbPayload);
        $response->assertStatus(201);

        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('database', $result);
    }

    public function test_show_database(): void
    {
        $username = $this->getCacheAsString('user.username');
        $databaseName = $this->getCacheAsString('database.database');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/mysql/databases/{$databaseName}");
        $response->assertStatus(200);
    }
}
