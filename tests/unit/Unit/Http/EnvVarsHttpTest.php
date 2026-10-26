<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\ApplyEnvVars;
use App\Jobs\RebuildProject;
use App\Lib\Project\ProjectRebuild;
use App\Models\SecretVaultEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * GET/PUT /projects/{username}/env-vars: the stored overrides read back (masked
 * unless `reveal=1`), and written without a redeploy.
 */
class EnvVarsHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    private function dindUser(array $details = []): User
    {
        return $this->makeUser('ev' . bin2hex(random_bytes(4)), array_merge([
            'template' => 'dind',
            'env_vars' => ['KEEP' => 'yes'],
            'env_override_keys' => ['KEEP', 'APP_KEY'],
        ], $details));
    }

    public function test_the_listing_masks_values_and_returns_the_applied_keys(): void
    {
        $user = $this->dindUser();

        $response = $this->getJson("/api/projects/{$user->username}/env-vars");

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.vars.0.key', 'KEEP');
        $response->assertJsonPath('data.vars.0.value', null);
        $response->assertJsonPath('data.vars.0.masked', true);
        $response->assertJsonPath('data.applied_keys', ['KEEP', 'APP_KEY']);
    }

    public function test_reveal_returns_the_values(): void
    {
        $user = $this->dindUser();

        $response = $this->getJson("/api/projects/{$user->username}/env-vars?reveal=1");

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.vars.0.value', 'yes');
        $response->assertJsonPath('data.vars.0.masked', false);
    }

    public function test_an_unknown_project_is_404(): void
    {
        $this->getJson('/api/projects/nosuchuser999/env-vars')->assertStatus(404);
    }

    public function test_a_php_hosting_project_is_422(): void
    {
        $user = $this->makeUser('ev' . bin2hex(random_bytes(4)), ['template' => 'php']);

        $this->getJson("/api/projects/{$user->username}/env-vars")
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'env_vars_dind_only');
    }

    public function test_a_merge_stores_the_change_and_queues_the_apply(): void
    {
        $user = $this->dindUser();

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['ADDED' => 'one', 'KEEP' => ''],
        ]);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.job_type', ApplyEnvVars::class);
        $response->assertJsonPath('data.status', Task::STATUS_QUEUED);
        $response->assertJsonPath('data.details.action', 'env_apply');
        $response->assertJsonPath('data.details.keys', ['ADDED']);
        $this->assertSame(['ADDED' => 'one'], $user->refresh()->getEnvVars());
        Queue::assertPushed(ApplyEnvVars::class, static fn (ApplyEnvVars $job): bool => $job->username === $user->username
            && $job->replaced === ['KEEP']);
    }

    public function test_replace_stores_only_the_new_map(): void
    {
        $user = $this->dindUser();

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['A' => '1'],
            'replace' => true,
        ]);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['A' => '1'], $user->refresh()->getEnvVars());
    }

    public function test_apply_none_stores_without_touching_the_containers(): void
    {
        $user = $this->dindUser();

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['A' => '1'],
            'apply' => 'none',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.keys', ['KEEP', 'A']);
        $this->assertSame(['KEEP' => 'yes', 'A' => '1'], $user->refresh()->getEnvVars());
        Queue::assertNotPushed(ApplyEnvVars::class);
    }

    public function test_a_php_hosting_project_is_refused_on_write(): void
    {
        $user = $this->makeUser('ev' . bin2hex(random_bytes(4)), ['template' => 'php']);

        $this->putJson("/api/projects/{$user->username}/env-vars", ['env_vars' => ['A' => '1']])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'env_vars_dind_only');
    }

    public function test_an_invalid_key_is_refused(): void
    {
        $user = $this->dindUser();

        $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['1BAD' => 'x'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'env_vars_invalid_key');
    }

    public function test_an_unknown_apply_value_is_refused(): void
    {
        $user = $this->dindUser();

        $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['A' => '1'],
            'apply' => 'later',
        ])->assertStatus(422);
    }

    public function test_an_empty_map_with_replace_clears_every_key(): void
    {
        $user = $this->dindUser();

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => [],
            'replace' => true,
            'apply' => 'none',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.keys', []);
        $this->assertSame([], $user->refresh()->getEnvVars());
    }

    public function test_an_empty_map_without_replace_keeps_the_stored_keys(): void
    {
        $user = $this->dindUser();

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => [],
            'apply' => 'none',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.keys', ['KEEP']);
        $this->assertSame(['KEEP' => 'yes'], $user->refresh()->getEnvVars());
    }

    public function test_a_null_map_is_refused(): void
    {
        $user = $this->dindUser();

        $this->putJson("/api/projects/{$user->username}/env-vars", ['env_vars' => null])
            ->assertStatus(422);
    }

    public function test_a_queued_env_apply_makes_another_write_409(): void
    {
        $user = $this->dindUser();
        $task = Task::start(jobType: ApplyEnvVars::class, queue: 'default', username: $user->username, details: [
            'action' => 'env_apply',
            'keys' => [],
        ]);

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", ['env_vars' => ['A' => '1']]);

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($task->id, $response->json('task_id'));
    }

    public function test_a_deploy_in_flight_is_409_with_its_task(): void
    {
        $user = $this->dindUser();
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $user->username);

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", ['env_vars' => ['A' => '1']]);

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($task->id, $response->json('task_id'));
    }

    public function test_a_vault_reference_is_stored_as_its_secret(): void
    {
        $user = $this->dindUser();
        /** @var SecretVaultEntry $entry */
        $entry = SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref' . bin2hex(random_bytes(6))),
            'type' => SecretVaultEntry::TYPE_ENV_VARS,
            'scope' => SecretVaultEntry::SCOPE_PROJECT,
            'project' => $user->username,
        ]);
        $entry->setSecret('s3cret');
        $entry->save();

        $response = $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['TOKEN' => $entry->reference()],
            'apply' => 'none',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['KEEP' => 'yes', 'TOKEN' => 's3cret'], $user->refresh()->getEnvVars());
    }
}
