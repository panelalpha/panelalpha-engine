<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\SystemTest::test_get_system_info
 */
class WebserverTest extends TestCase
{
    public function test_get_webserver_system_info(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/system/info');
        $response->assertStatus(200);

        $webserver = $response->json('data.webserver');
        $this->assertNotNull($webserver, 'data.webserver is missing from system info');
        $this->assertIsArray($webserver);
        $this->assertArrayHasKey('name', $webserver);
        $this->assertNotNull($webserver['name']);
    }

    public function test_get_available_php_versions(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/php/available-versions');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data), 'Expected at least one available PHP version');
    }

    public function test_reject_invalid_webserver_change(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/change-webserver', [
            'webserver' => 'not-a-real-webserver-xyz',
        ]);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    public function test_reject_non_nginx_proxy_webserver_change(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/change-webserver', [
            'new_webserver' => 'nginx',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['new_webserver']);
    }

    public function test_reject_empty_webserver_config_update(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/webserver-config', []);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }
}
