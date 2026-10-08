<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * Deletes the app user created by AppUsersTest, then deletes the git_repo dind user.
 * Must run after all AppUsersTest cases.
 *
 * @depends Tests\Feature\AppUsersTest::test_sso_returns_url
 * @depends Tests\Feature\AppUsersTest::test_reset_app_user_password
 * @depends Tests\Feature\AppUsersTest::test_created_user_appears_in_list
 */
class AppUsersDeleteUserTest extends TestCase
{
    private function skipUnlessGitRepo(): void
    {
        if (empty(env('GIT_REPO'))) {
            $this->markTestSkipped('GIT_REPO env var is not set');
        }
    }

    #[UnsetsCache('app_user')]
    public function test_delete_app_user(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username  = $this->getCacheAsString('git_repo_user.username');
        $appUserId = $this->getCacheAsString('app_user.id');

        $response = $this->deleteJson("/api/users/{$username}/app/users/{$appUserId}");
        $response->assertStatus(204);

        $this->unsetCache('app_user');
    }

    /**
     * @depends Tests\Feature\AppUsersDeleteUserTest::test_deleted_user_absent_from_list
     */
    #[UnsetsCache('git_repo_user')]
    public function test_delete_git_repo_dind_user(): void
    {
        $this->skipUnlessGitRepo();
        $this->authenticate();
        $username = $this->getCacheAsString('git_repo_user.username');

        $response = $this->deleteJson("/api/users/{$username}");
        $response->assertStatus(200);

        $this->unsetCache('git_repo_user');
    }
}
