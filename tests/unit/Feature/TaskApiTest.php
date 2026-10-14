<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class TaskApiTest extends TestCase
{
    public function test_unauthenticated_get_returns_401(): void
    {
        $response = $this->getJson('/api/tasks/1');

        $response->assertStatus(401);
    }

    public function test_authenticated_get_of_unknown_id_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/tasks/999999999');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Not found');
    }
}
