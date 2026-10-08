<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class StagingTest extends TestCase
{
    public function test_staging_unknown_user_404(): void
    {
        if (!$this->hasCache('api_token')) {
            $this->markTestSkipped('Missing cache `api_token`');
        }

        $this->authenticate();
        $response = $this->postJson('/api/projects/nosuchuser999/staging', []);

        $response->assertStatus(404);
    }

    public function test_push_unknown_users_404(): void
    {
        if (!$this->hasCache('api_token')) {
            $this->markTestSkipped('Missing cache `api_token`');
        }

        $this->authenticate();
        $response = $this->postJson('/api/projects/nosuchuser999/push', ['target' => 'also-missing']);

        $response->assertStatus(404);
    }
}
