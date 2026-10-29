<?php

namespace Tests\Feature;

use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class Ipv4NatMapApiTest extends TestCase
{
    public function test_list_ipv4_nat_maps(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/system/ipv4-nat-maps');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [],
        ]);
    }

    public function test_upsert_ipv4_nat_map(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/ipv4-nat-maps', [
            'local_ip' => TestNetwork::private(5),
            'public_ip' => TestNetwork::public(50),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.local_ip', TestNetwork::private(5));
        $response->assertJsonPath('data.public_ip', TestNetwork::public(50));
    }

    public function test_upsert_ipv4_nat_map_rejects_same_ip(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/ipv4-nat-maps', [
            'local_ip' => TestNetwork::private(5),
            'public_ip' => TestNetwork::private(5),
        ]);

        $response->assertStatus(422);
    }

    public function test_delete_ipv4_nat_map(): void
    {
        $this->authenticate();
        $createResponse = $this->putJson('/api/system/ipv4-nat-maps', [
            'local_ip' => TestNetwork::private(6),
            'public_ip' => TestNetwork::public(51),
        ]);
        $createResponse->assertStatus(200);
        $id = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/system/ipv4-nat-maps/{$id}");
        $response->assertStatus(200);
    }

    public function test_system_info_includes_nat_mode(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/system/info');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'ipv4_nat_mode',
                'ipv4_nat_maps',
            ],
        ]);
    }
}
