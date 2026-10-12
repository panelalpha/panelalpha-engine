<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureProjectNotProtected;
use App\Jobs\ApplyEnvVars;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The `protected` project setting over HTTP: a token is refused every delete,
 * stop and redeploy of a protected project before anything runs or is queued,
 * and may not change the setting that refuses it.
 */
class ProjectProtectedHttpTest extends TestCase
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

    public function test_the_api_may_not_delete_a_protected_project(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $this->deleteJson("/api/projects/{$user->username}")
            ->assertStatus(409)
            ->assertJsonPath('code', EnsureProjectNotProtected::CODE);

        Queue::assertNothingPushed();
        $this->assertNotNull(User::findByUsername($user->username));
    }

    public function test_a_rebuild_queues_nothing_on_a_protected_project(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $this->postJson("/api/projects/{$user->username}/rebuild")
            ->assertStatus(409)
            ->assertJsonPath('code', EnsureProjectNotProtected::CODE);

        Queue::assertNothingPushed();
        $this->assertSame(0, Task::count());
    }

    public function test_a_read_on_a_protected_project_still_answers(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $response = $this->getJson("/api/projects/{$user->username}")->assertStatus(200);

        $this->assertTrue($response->json('data.protected'));
    }

    public function test_an_unprotected_project_carries_the_flag_as_false(): void
    {
        $user = $this->makeUser('pp' . bin2hex(random_bytes(4)), ['template' => 'dind']);
        $this->actingAsToken();

        $this->getJson("/api/projects/{$user->username}")
            ->assertStatus(200)
            ->assertJsonPath('data.protected', false);
    }

    public function test_the_setting_is_read_only_over_the_api(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $this->putJson("/api/projects/{$user->username}/settings/protected", ['value' => 'on'])
            ->assertStatus(403);
        $this->deleteJson("/api/projects/{$user->username}/settings/protected")
            ->assertStatus(403);

        $this->assertTrue($user->refresh()->isProtected());
    }

    public function test_the_api_reads_the_setting_back(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $this->getJson("/api/projects/{$user->username}/settings/protected")
            ->assertStatus(200)
            ->assertJsonPath('data.value', User::PROTECTED_ON);
    }

    public function test_the_console_sets_and_clears_the_setting(): void
    {
        $user = $this->makeUser('pp' . bin2hex(random_bytes(4)), ['template' => 'dind']);

        $this->artisan('project:settings:set', [
            'key' => 'protected',
            'value' => 'on',
            '--project' => $user->username,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue($user->refresh()->isProtected());

        $this->actingAsToken();
        $this->getJson("/api/projects/{$user->username}")->assertJsonPath('data.protected', true);

        $this->artisan('project:settings:unset', [
            'key' => 'protected',
            '--project' => $user->username,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse($user->refresh()->isProtected());
    }

    public function test_env_vars_with_apply_none_are_allowed_on_a_protected_project(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $this->putJson("/api/projects/{$user->username}/env-vars", [
            'env_vars' => ['ADDED' => 'one'],
            'apply' => 'none',
        ])->assertStatus(200);

        $this->assertSame(['ADDED' => 'one'], $user->refresh()->getEnvVars());
        Queue::assertNothingPushed();
    }

    public function test_env_vars_with_the_default_apply_are_refused_on_a_protected_project(): void
    {
        $user = $this->protectedUser();
        $this->actingAsToken();

        $this->putJson("/api/projects/{$user->username}/env-vars", ['env_vars' => ['ADDED' => 'one']])
            ->assertStatus(409)
            ->assertJsonPath('code', EnsureProjectNotProtected::CODE);

        $this->assertSame([], $user->refresh()->getEnvVars());
        Queue::assertNothingPushed();
    }

    public function test_the_same_call_passes_for_an_unprotected_project(): void
    {
        $user = $this->makeUser('pp' . bin2hex(random_bytes(4)), ['template' => 'dind']);
        $this->actingAsToken();

        $this->putJson("/api/projects/{$user->username}/env-vars", ['env_vars' => ['ADDED' => 'one']])
            ->assertStatus(202);

        Queue::assertPushed(ApplyEnvVars::class);
    }

    public function test_a_push_onto_a_protected_target_is_refused(): void
    {
        $source = $this->makeUser('pp' . bin2hex(random_bytes(4)), ['template' => 'dind']);
        $target = $this->protectedUser();
        $this->actingAsToken();

        $this->postJson("/api/projects/{$source->username}/push", ['target' => $target->username])
            ->assertStatus(409)
            ->assertJsonPath('code', EnsureProjectNotProtected::CODE);

        Queue::assertNothingPushed();
    }

    private function protectedUser(): User
    {
        $user = $this->makeUser('pp' . bin2hex(random_bytes(4)), ['template' => 'dind']);
        $user->setDetails([User::DETAIL_PROTECTED => User::PROTECTED_ON]);
        $user->save();

        return $user;
    }

    private function actingAsToken(): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => 933311,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'api',
            'token' => hash('sha256', 'g32-protected-api'),
            'abilities' => TokenAbilities::build(api: true, mcp: true),
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
        request()->setUserResolver(fn () => $admin);
    }
}
