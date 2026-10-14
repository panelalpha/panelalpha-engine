<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class UsersValidationTest extends TestCase
{
    public function test_rejects_invalid_usernames(): void
    {
        $this->authenticate();
        $invalidUsernames = [
            'ab',               // too short
            'a b c',            // spaces
            'user!name',        // special chars
            str_repeat('a', 33), // too long
            '-leading-dash',
            'trailing-dash-',
        ];
        foreach ($invalidUsernames as $username) {
            $response = $this->postJson('/api/users', [
                'username' => $username,
                'domain' => 'valid-domain.test',
            ]);
            $this->assertContains(
                $response->getStatusCode(),
                [400, 422],
                "Expected 400/422 for username: '{$username}'"
            );
        }
    }

    public function test_rejects_null_required_fields(): void
    {
        $this->authenticate();
        $response = $this->postJson('/api/users', [
            'username' => null,
            'domain' => null,
        ]);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    public function test_rejects_duplicate_username(): void
    {
        $existingUsername = $this->getCacheAsString('user.username');
        $this->authenticate();

        $response = $this->postJson('/api/users', [
            'username' => $existingUsername,
            'domain' => 'duplicate-' . strtolower(Str::random(6)) . '.test',
        ]);
        $this->assertContains($response->getStatusCode(), [409, 422],
            "Expected conflict status for duplicate username: '{$existingUsername}'"
        );
    }

    public function test_rejects_invalid_email_on_update(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $response = $this->putJson("/api/users/{$username}", [
            'email' => 'not-an-email',
        ]);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    public function test_rejects_sql_injection_in_username(): void
    {
        $this->authenticate();
        $sqlPatterns = [
            "admin' OR '1'='1",
            "user; DROP TABLE users;--",
            "1 OR 1=1",
        ];
        foreach ($sqlPatterns as $pattern) {
            $response = $this->postJson('/api/users', [
                'username' => $pattern,
                'domain' => 'valid.test',
            ]);
            $this->assertContains(
                $response->getStatusCode(),
                [400, 422],
                "Expected 400/422 for SQL injection pattern: '{$pattern}'"
            );
        }
    }

    public function test_xss_payloads_not_stored(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $payload = '<script>alert(1)</script>';
        $response = $this->putJson("/api/users/{$username}", [
            'email' => "test@test.test",
            'notes' => $payload,
        ]);

        // Either rejected or stored safely — either way the raw script tag must not appear
        if ($response->getStatusCode() === 200) {
            $this->assertStringNotContainsString('<script', (string)$response->getContent());
        } else {
            $this->assertContains($response->getStatusCode(), [200, 400, 422]);
        }
    }
}
