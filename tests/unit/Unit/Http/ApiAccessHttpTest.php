<?php

namespace Tests\Unit\Http;

use App\Jobs\ApplyEnvVars;
use App\Lib\Project\ApiAccess;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\User;
use App\Rules\EnvVarKeysNotReserved;
use App\System;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * The panel's half of the Engine API allowance: who may use the API, with what,
 * and that an account cannot set the engine's own variables for itself.
 */
class ApiAccessHttpTest extends TestCase
{
    use InMemoryDatabase;

    /** A container project, with the Linux user every real one is created with. */
    private const CONTAINER_DETAILS = ['template' => 'dind', 'UID' => 1000, 'GID' => 1000];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->app->instance(System::class, new RecordingSystem());
        Queue::fake();
        $this->makeUser('alice', self::CONTAINER_DETAILS);
        $this->makeUser('bob', self::CONTAINER_DETAILS);
        $this->makeUser('carol');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_put_allows_a_project_and_answers_the_entry_without_the_token(): void
    {
        $response = $this->putJson('/api/system/api-access/alice', ['scope' => 'self']);

        $response->assertStatus(200);
        $this->assertSame('alice', $response->json('data.project'));
        $this->assertSame('self', $response->json('data.scope'));
        $this->assertFalse($response->json('data.mcp'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayHasKey('token_id', $response->json('data'));

        $token = PersonalAccessToken::findOrFail($response->json('data.token_id'));
        $this->assertSame(['api', 'project', 'project:alice'], $token->abilities);
    }

    public function test_put_with_a_list_scope_mints_a_token_for_those_projects(): void
    {
        $response = $this->putJson('/api/system/api-access/alice', ['scope' => ['alice', 'bob'], 'mcp' => true]);

        $response->assertStatus(200);
        $this->assertSame(['alice', 'bob'], $response->json('data.scope'));
        $this->assertTrue($response->json('data.mcp'));
        $this->assertSame(
            ['api', 'mcp', 'project', 'project:alice', 'project:bob'],
            PersonalAccessToken::findOrFail($response->json('data.token_id'))->abilities,
        );
    }

    public function test_put_refuses_a_scope_naming_no_project(): void
    {
        $response = $this->putJson('/api/system/api-access/alice', ['scope' => ['nobody']]);

        $response->assertStatus(422);
        $this->assertSame('scope', $response->json('problems.0.field'));
        $this->assertSame(ApiAccess::PROJECT_UNKNOWN, $response->json('problems.0.code'));
    }

    public function test_put_and_delete_answer_404_for_a_project_that_does_not_exist(): void
    {
        $this->putJson('/api/system/api-access/nobody', ['scope' => 'self'])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Project not found']);

        $this->deleteJson('/api/system/api-access/nobody')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Project not found']);
    }

    public function test_rotate_replaces_the_token_and_names_the_apply_task(): void
    {
        $first = $this->putJson('/api/system/api-access/alice', ['scope' => 'self'])->json('data.token_id');

        $response = $this->postJson('/api/system/api-access/alice/rotate');

        $response->assertStatus(202);
        $this->assertGreaterThan(0, $response->json('task_id'));
        $this->assertNotSame($first, $response->json('data.token_id'));
        $this->assertSame((int) $first, $response->json('data.pending_revoke_token_id'));
        $this->assertSame(
            'previous token still valid until the apply that replaces it finishes',
            $response->json('data.warning'),
            'nothing has tried to apply it yet'
        );
        $this->assertSame('self', $response->json('data.scope'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertNull(
            PersonalAccessToken::findOrFail((int) $first)->revoked_at,
            'the token it replaces waits for the apply'
        );
    }

    public function test_rotate_refuses_a_project_that_is_not_allowed(): void
    {
        $response = $this->postJson('/api/system/api-access/alice/rotate');

        $response->assertStatus(422);
        $this->assertSame(ApiAccess::NOT_ALLOWED, $response->json('problems.0.code'));
    }

    public function test_a_failed_apply_is_reported_in_the_listing(): void
    {
        $this->putJson('/api/system/api-access/alice', ['scope' => 'self']);
        $this->postJson('/api/system/api-access/alice/rotate');

        $entry = $this->getJson('/api/system/api-access')->json('data.0');

        $this->assertIsInt($entry['pending_revoke_token_id']);
        $this->assertSame(
            'previous token still valid until the apply that replaces it finishes',
            $entry['warning']
        );
    }

    /** A classic project has no container to receive any of it. */
    public function test_a_project_that_is_not_a_container_project_is_refused(): void
    {
        $response = $this->putJson('/api/system/api-access/carol', ['scope' => 'self']);

        $response->assertStatus(422);
        $this->assertSame('project', $response->json('problems.0.field'));
        $this->assertSame(ApiAccess::CONTAINER_ONLY, $response->json('problems.0.code'));
        $this->assertSame([], $this->getJson('/api/system/api-access')->json('data'));
    }

    /** Allowing what is already allowed is not a rotation. */
    public function test_put_twice_with_the_same_scope_changes_nothing(): void
    {
        $first = $this->putJson('/api/system/api-access/alice', ['scope' => 'self'])->json('data.token_id');
        Queue::fake();

        $second = $this->putJson('/api/system/api-access/alice', ['scope' => 'self'])->json('data.token_id');

        $this->assertSame($first, $second);
        Queue::assertNotPushed(ApplyEnvVars::class);
    }

    public function test_the_list_never_carries_a_token(): void
    {
        $this->putJson('/api/system/api-access/alice', ['scope' => 'all']);

        $response = $this->getJson('/api/system/api-access');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertArrayNotHasKey('token', $response->json('data.0'));
        $this->assertIsInt($response->json('data.0.token_id'));
        $this->assertSame('all', $response->json('data.0.scope'));
    }

    public function test_delete_takes_the_allowance_and_the_token_away(): void
    {
        $tokenId = (int) $this->putJson('/api/system/api-access/alice', ['scope' => 'self'])->json('data.token_id');

        $response = $this->deleteJson('/api/system/api-access/alice');

        $response->assertStatus(200);
        $response->assertExactJson(['data' => ['project' => 'alice', 'removed' => true]]);
        $this->assertNotNull(PersonalAccessToken::findOrFail($tokenId)->revoked_at);
        $this->assertSame([], $this->getJson('/api/system/api-access')->json('data'));
    }

    public function test_deleting_an_allowance_that_is_not_there_changes_nothing(): void
    {
        $this->deleteJson('/api/system/api-access/alice')
            ->assertStatus(200)
            ->assertExactJson(['data' => ['project' => 'alice', 'removed' => false]]);
    }

    public function test_the_engines_own_variables_are_refused_in_env_vars(): void
    {
        $response = $this->putJson('/api/projects/alice/env-vars', [
            'env_vars' => [ApiAccess::ENV_TOKEN => 'mine'],
            'apply' => 'none',
        ]);

        $response->assertStatus(422);
        $this->assertSame('env_vars', $response->json('problems.0.field'));
        $this->assertSame(EnvVarKeysNotReserved::CODE, $response->json('problems.0.code'));
        $this->assertSame([], User::findByUsername('alice')->getEnvVars());
    }

    public function test_a_refused_name_is_never_shown_by_the_env_vars_read(): void
    {
        $user = User::findByUsername('alice');
        $user->setDetails(['env_vars' => ['APP_ENV' => 'production', ApiAccess::ENV_URL => 'https://mine']]);
        $user->save();

        $response = $this->getJson('/api/projects/alice/env-vars');

        $response->assertStatus(200);
        $this->assertSame(['APP_ENV'], array_column($response->json('data.vars'), 'key'));
    }
}
