<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class SystemTest extends TestCase
{
    public function test_get_system_info(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/system/info');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertNotNull($response->json('data'));
    }

    public function test_get_current_metrics(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/metrics/current');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotNull($data);
        assert(is_array($data));
        if (isset($data['avg_cpu_percent'])) {
            $this->assertGreaterThanOrEqual(0, $data['avg_cpu_percent']);
        }
        if (isset($data['avg_ram_percent'])) {
            $this->assertGreaterThanOrEqual(0, $data['avg_ram_percent']);
        }
    }

    public function test_get_last_5_minutes_metrics(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/metrics/last-5-minutes');
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data'));
    }

    public function test_get_last_hour_metrics(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/metrics/last-hour');
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data'));
    }

    public function test_get_last_12_hours_metrics(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/metrics/last-12-hours');
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data'));
    }

    public function test_get_last_hour_averages(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/metrics/last-hour-averages');
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data'));
    }

    public function test_malformed_metrics_params(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/metrics/current?from=not-a-timestamp&to=also-bad');
        $this->assertContains($response->getStatusCode(), [200, 400, 422]);
        if (in_array($response->getStatusCode(), [400, 422])) {
            $this->assertMatchesRegularExpression('/from|to|bucket|metrics|invalid|validation/i', (string)$response->getContent());
        }
    }
}
