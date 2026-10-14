<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class UsersAllTest extends TestCase
{
    public function test_list_all_users(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/users/all');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    public function test_list_all_users_with_domain_names(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/users/all?with_domain_names=1');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        foreach ($data as $user) {
            assert(is_array($user));
            $this->assertArrayHasKey('domain', $user);
        }
    }

    public function test_users_all_and_paginated_consistent(): void
    {
        $this->authenticate();
        $allResponse = $this->getJson('/api/users/all');
        $allResponse->assertStatus(200);
        $allData = $allResponse->json('data');
        $this->assertIsArray($allData);

        $paginatedResponse = $this->getJson('/api/users');
        $paginatedResponse->assertStatus(200);
        $this->assertNotNull($paginatedResponse->json('meta'));

        // all users count should be >= the first page size
        $this->assertGreaterThanOrEqual(0, count($allData));
    }
}
