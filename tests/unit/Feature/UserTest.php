<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\Attributes\UpdatesCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class UserTest extends TestCase
{
    #[SetsCache('user')]
    public function test_create_user(): void
    {
        $this->skipIfCached('user');

        $this->authenticate();
        $rand = strtolower(Str::random(6));
        $response = $this->postJson('/api/users', [
            'username' => "test{$rand}",
            'domain' => "test{$rand}.test",
        ]);
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'username', 'email', 'domain'],
        ]);

        $user = $response->json('data');
        assert(is_array($user));
        $this->setCache('user', $user);
    }

    public function test_get_users(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/users');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'username', 'email', 'domain']
            ],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total']
        ]);
    }

    public function test_verify_new_username(): void
    {
        $this->authenticate();
        $uniqueUsername = 'a' . strtolower(Str::random(9));
        $payload = ['username' => $uniqueUsername];

        $response = $this->postJson('/api/users/verify-new-username', $payload);
        $response->assertStatus(200);
        $response->assertJson(['valid' => true]);
    }

    public function test_rebuild_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->postJson("/api/users/{$username}/rebuild");
        $response->assertStatus(202);
        // The suite's queue is sync, so the job has run by the time the 202 is back.
        $this->assertSame('completed', $this->getJson('/api/tasks/' . $response->json('data.id'))->json('data.status'));
    }

    public function test_show_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}");
        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['id', 'username', 'email']]);
    }

    #[UpdatesCache('user')]
    public function test_update_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $rand = strtolower(Str::random(6));
        $updatePayload = ['email' => "test{$rand}@test.test"];
        $response = $this->putJson("/api/users/{$username}", $updatePayload);
        $response->assertStatus(200);
        $response->assertJson(['data' => ['email' => "test{$rand}@test.test"]]);

        $user = $response->json('data');
        assert(is_array($user));
        $this->setCache('user', $user);
    }
}
