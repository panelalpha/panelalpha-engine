<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_test_connection(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/test-connection');
        $response->assertStatus(200);
        $this->assertTrue($response->json('success') === true);
    }

    public function test_system_info_available(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/system/info');
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data'));
    }
}
