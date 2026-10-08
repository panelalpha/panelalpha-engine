<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * Tests for the app user management endpoints (GET/POST/DELETE/PUT/POST).
 * Requires a running dind user whose git_repo is set to the value of the
 * GIT_REPO environment variable.  All tests are skipped when GIT_REPO is empty.
 *
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\AppUsersCreateUserTest::test_create_git_repo_user
 */
class AppUsersTest extends TestCase
{
    private function skipUnlessGitRepo(): void
    {
        if (empty(env('GIT_REPO'))) {
            $this->markTestSkipped('GIT_REPO env var is not set');
        }
    }

    // ------------------------------------------------------------------
    // list
    // ------------------------------------------------------------------

    public function test_list_app_users(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');

        $response = $this->getJson("/api/users/{$username}/app/users");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [
            '*' => ['id', 'username', 'role'],
        ]]);
    }

    // ------------------------------------------------------------------
    // add
    // ------------------------------------------------------------------

    #[SetsCache('app_user')]
    public function test_add_app_user(): void
    {
        $this->skipUnlessGitRepo();
        $this->skipIfCached('app_user');
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');

        // Discover the first available non-admin role so the test is app-agnostic.
        $rolesResponse = $this->getJson("/api/users/{$username}/app/roles");
        $rolesResponse->assertStatus(200);
        $roles = $rolesResponse->json('data') ?? [];
        if (empty($roles)) {
            $this->markTestSkipped('App returned no assignable roles');
        }
        $role = (string) $roles[0];

        $login    = 'patest' . strtolower(Str::random(5));
        $email    = $login . '@panelalpha.test';
        $password = Str::random(16);

        $response = $this->postJson("/api/users/{$username}/app/users", [
            'login'    => $login,
            'email'    => $email,
            'password' => $password,
            'role'     => $role,
        ]);
        $response->assertStatus(201);
        $response->assertJsonStructure(['data' => ['id']]);

        $this->setCache('app_user', [
            'id'       => (string)$response->json('data.id'),
            'login'    => $login,
            'email'    => $email,
            'password' => $password,
        ]);
    }

    public function test_add_app_user_validates_required_fields(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');

        $response = $this->postJson("/api/users/{$username}/app/users", []);
        $response->assertStatus(422);
    }

    public function test_add_app_user_validates_email_format(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');

        $response = $this->postJson("/api/users/{$username}/app/users", [
            'login'    => 'validlogin',
            'email'    => 'not-an-email',
            'password' => Str::random(16),
            'role'     => 'subscriber',
        ]);
        $response->assertStatus(422);
    }

    public function test_add_app_user_validates_password_min_length(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');

        $response = $this->postJson("/api/users/{$username}/app/users", [
            'login'    => 'validlogin',
            'email'    => 'valid@test.test',
            'password' => 'short',
            'role'     => 'subscriber',
        ]);
        $response->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // created user appears in list
    // ------------------------------------------------------------------

    /**
     * @depends Tests\Feature\AppUsersTest::test_add_app_user
     */
    public function test_created_user_appears_in_list(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username  = $this->getCacheAsString('git_repo_user.username');
        $appUserId = $this->getCacheAsString('app_user.id');

        $response = $this->getJson("/api/users/{$username}/app/users");
        $response->assertStatus(200);

        $ids = array_column($response->json('data') ?? [], 'id');
        $this->assertContains($appUserId, $ids, 'Newly created app user not found in list');
    }

    // ------------------------------------------------------------------
    // reset password
    // ------------------------------------------------------------------

    /**
     * @depends Tests\Feature\AppUsersTest::test_add_app_user
     */
    public function test_reset_app_user_password(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username  = $this->getCacheAsString('git_repo_user.username');
        $appUserId = $this->getCacheAsString('app_user.id');

        $response = $this->putJson("/api/users/{$username}/app/users/{$appUserId}/password", [
            'password' => Str::random(16),
        ]);
        $response->assertStatus(204);
    }

    public function test_reset_password_validates_min_length(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username  = $this->getCacheAsString('git_repo_user.username');
        $appUserId = $this->getCacheAsString('app_user.id');

        $response = $this->putJson("/api/users/{$username}/app/users/{$appUserId}/password", [
            'password' => 'short',
        ]);
        $response->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // sso
    // ------------------------------------------------------------------

    /**
     * @depends Tests\Feature\AppUsersTest::test_add_app_user
     */
    public function test_sso_returns_url(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username  = $this->getCacheAsString('git_repo_user.username');
        $appUserId = $this->getCacheAsString('app_user.id');

        $response = $this->postJson("/api/users/{$username}/app/users/{$appUserId}/sso");

        $response->assertStatus(200);
        $response->assertJsonStructure(['url']);
        $this->assertNotEmpty($response->json('url'), 'SSO URL must not be empty');
        $this->assertStringStartsWith('http', $response->json('url'), 'SSO URL must be an http(s) URL');
    }
}
