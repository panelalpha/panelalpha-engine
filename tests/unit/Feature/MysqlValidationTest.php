<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\DatabaseTest::test_create_database
 */
class MysqlValidationTest extends TestCase
{
    public function test_rejects_invalid_database_name(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $invalidNames = [
            "db'; DROP TABLE users;--",
            "db name with spaces",
            str_repeat('a', 65), // too long
            '',
        ];

        foreach ($invalidNames as $name) {
            $response = $this->postJson("/api/users/{$username}/mysql/databases", [
                'name' => $name,
            ]);
            $this->assertContains(
                $response->getStatusCode(),
                [400, 422],
                "Expected 400/422 for invalid database name: '{$name}'"
            );
        }
    }

    public function test_rejects_duplicate_database_name(): void
    {
        $username = $this->getCacheAsString('user.username');
        $existingDatabase = $this->getCacheAsString('database.database');
        $this->authenticate();

        // The full DB name returned by the API includes the username prefix (e.g. user_dbname)
        // Strip the prefix to get the base name the API expects
        $prefix = $username . '_';
        $baseName = str_starts_with($existingDatabase, $prefix)
            ? substr($existingDatabase, strlen($prefix))
            : $existingDatabase;

        $response = $this->postJson("/api/users/{$username}/mysql/databases", [
            'name' => $baseName,
        ]);
        $this->assertContains(
            $response->getStatusCode(),
            [409, 422],
            "Expected conflict status for duplicate database name: '{$baseName}'"
        );
    }
}
