<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthGuardsTest extends TestCase
{
    public function test_rejects_without_bearer_token(): void
    {
        $response = $this->getJson('/api/users');
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    public function test_rejects_invalid_bearer_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalidtoken000')
            ->getJson('/api/system/info');
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}
