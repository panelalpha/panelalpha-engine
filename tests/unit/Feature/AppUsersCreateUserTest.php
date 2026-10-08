<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * Creates a dind user with git_repo set from the GIT_REPO env var.
 * All tests are skipped when GIT_REPO is empty.
 * This must run before AppUsersTest and AppUsersDeleteUserTest.
 *
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class AppUsersCreateUserTest extends TestCase
{
    private function skipUnlessGitRepo(): void
    {
        if (empty(env('GIT_REPO'))) {
            $this->markTestSkipped('GIT_REPO env var is not set');
        }
    }

    #[SetsCache('git_repo_user')]
    public function test_create_git_repo_user(): void
    {
        $this->skipUnlessGitRepo();
        $this->skipIfCached('git_repo_user');
        $this->authenticate();

        $rand    = strtolower(Str::random(6));
        $gitRepo = (string) env('GIT_REPO');

        $response = $this->postJson('/api/users', [
            'git_repo' => $gitRepo,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['data' => ['id', 'username']]);

        $user = $response->json('data');
        assert(is_array($user));
        $this->setCache('git_repo_user', $user);
    }

    /**
     * @depends Tests\Feature\AppUsersCreateUserTest::test_create_git_repo_user
     */
    public function test_app_install(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');
        $domain   = $this->getCacheAsString('git_repo_user.domain');

        $response = $this->getJson("/api/users/{$username}/app/info");
        $response->assertStatus(200);

        $caps = $response->json('data') ?? [];
        if (!in_array('install', $caps, true)) {
            $this->markTestSkipped('App does not support the install action');
        }

        $response = $this->postJson("/api/users/{$username}/app/install", [
            'url'            => "https://{$domain}",
            'title'          => $domain,
            'admin_user'     => 'admin',
            'admin_email'    => "admin@{$domain}",
            'admin_password' => 'Admin1234!',
        ]);
        $response->assertStatus(204);
    }
}
