<?php

namespace Tests\Unit\Deploy;

use App\Jobs\ApplyEnvVars;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Project\ApiAccess;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Runtime;
use Mockery;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * The queued env var apply: rewrite the env files, refresh the run file's
 * `environment:` and start the stack, with no rebuild.
 */
class ApplyEnvVarsJobTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_it_rewrites_the_env_and_starts_the_stack(): void
    {
        $user = $this->dindUser(['FOO' => 'bar']);
        $runtime = $this->runtimeExpecting(['FOO' => 'bar'], ['OLD'], 0);

        $task = $this->runJob($user, $runtime, ['OLD']);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
    }

    public function test_a_failed_compose_up_fails_the_task(): void
    {
        $user = $this->dindUser(['FOO' => 'bar']);
        $runtime = $this->runtimeExpecting(['FOO' => 'bar'], ['OLD'], 1);

        $task = $this->runJob($user, $runtime, ['OLD'], 'compose up boom');

        $this->assertSame(Task::STATUS_FAILED, $task->status);
    }

    public function test_it_holds_the_deploy_lock_while_it_applies(): void
    {
        $user = $this->dindUser(['FOO' => 'bar']);

        $writer = Mockery::mock(ComposeWriter::class);
        $writer->shouldReceive('mergeAccountEnv')->once()->with(['FOO' => 'bar'], []);
        $writer->shouldReceive('mergeApiAccessEnv')->once()->andReturn(false);

        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('applyProjectEnvVars')->once()->andReturnUsing(function () use ($user): void {
            $this->assertSame(DeployLock::HOLDER_ENV_VARS, DeployLock::heldBy($user->username));
        });
        $runtime->shouldReceive('composeWriter')->twice()->andReturn($writer);
        $runtime->shouldReceive('system')->andReturn(new RecordingSystem());
        $runtime->shouldReceive('projectAction')->once()->with('up')->andReturn([
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 0,
        ]);

        $task = $this->runJob($user, $runtime, []);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertNull(DeployLock::heldBy($user->username), 'the lock is released');
    }

    /**
     * A rotation hands the new token to the application first and retires the
     * old one only once the stack is up on it.
     */
    public function test_the_token_this_apply_replaces_is_revoked_once_the_stack_is_up(): void
    {
        $user = $this->dindUser([]);
        $token = $this->mintedToken();

        $task = $this->runJob($user, $this->runtimeExpecting([], [], 0), [], null, (int) $token->getKey());

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($token->refresh()->revoked_at);
    }

    /** A failed apply leaves both tokens valid, and the task says what happened. */
    public function test_the_replaced_token_survives_a_failed_apply(): void
    {
        $user = $this->dindUser([]);
        $token = $this->mintedToken();

        $task = $this->runJob($user, $this->runtimeExpecting([], [], 1), [], 'compose up boom', (int) $token->getKey());

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertNull($token->refresh()->revoked_at);
    }

    private function mintedToken(): PersonalAccessToken
    {
        $token = Admin::rootAccount()->createToken('api-access:test', ['api'])->accessToken;
        $this->assertInstanceOf(PersonalAccessToken::class, $token);

        return $token;
    }

    /**
     * An allowance that records the token a failed replacement left behind has it
     * revoked -- and the record cleared -- by the apply that finally succeeds.
     */
    public function test_a_successful_apply_retires_the_token_an_earlier_failure_left_tracked(): void
    {
        $user = $this->dindUser([]);
        $left = $this->mintedToken();
        $replaced = $this->mintedToken();
        $access = new ApiAccess(new RecordingSystem());
        Setting::set(ApiAccess::SETTING, (string) json_encode([[
            'project' => $user->username,
            'scope' => ApiAccess::SCOPE_SELF,
            'mcp' => false,
            'token_id' => (int) $replaced->getKey(),
            'token' => 'stored',
            'allowed_at' => now()->toIso8601String(),
            'pending_revoke_token_id' => (int) $left->getKey(),
        ]]));

        $task = $this->runJob($user, $this->runtimeExpecting([], [], 0), [], null, (int) $replaced->getKey());

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($left->refresh()->revoked_at, 'the tracked token is retired');
        $this->assertNotNull($replaced->refresh()->revoked_at, 'and so is the one this apply replaced');
        $this->assertNull($access->entryFor($user)['pending_revoke_token_id'], 'and nothing is left tracked');
    }

    public function test_it_fails_when_the_deploy_lock_is_held(): void
    {
        $user = $this->dindUser(['FOO' => 'bar']);

        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldNotReceive('applyProjectEnvVars');

        $lock = DeployLock::acquireFor($user->username, DeployLock::HOLDER_DELETE);
        try {
            $task = $this->runJob($user, $runtime, [], 'already running');
        } finally {
            $lock->release();
        }

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('deploy_already_running', $task->details['problems'][0]['code'] ?? null);
    }

    /**
     * @param array<string, string> $details
     */
    private function dindUser(array $envVars): User
    {
        return $this->makeUser('ap' . bin2hex(random_bytes(4)), [
            'template' => 'dind',
            'env_vars' => $envVars,
        ]);
    }

    private function runJob(User $user, Dind $runtime, array $replaced, ?string $expectedError = null, ?int $revokeTokenId = null): Task
    {
        $this->bindRuntime($user, $runtime);

        $task = Task::start(jobType: ApplyEnvVars::class, queue: 'default', username: $user->username, details: [
            'action' => 'env_apply',
            'keys' => array_keys($user->getEnvVars()),
        ]);
        $job = (new ApplyEnvVars($user->username, $replaced, (int) $user->getKey(), $revokeTokenId))->attachTask($task);

        try {
            $job->handle();
        } catch (RuntimeException $e) {
            $this->assertNotNull($expectedError, 'unexpected job failure: ' . $e->getMessage());
            $this->assertStringContainsString($expectedError, $e->getMessage());
        }

        return $task->refresh();
    }

    /**
     * @param array<string, string> $vars
     * @param list<string> $replaced
     */
    private function runtimeExpecting(array $vars, array $replaced, int $exitCode): Dind
    {
        $writer = Mockery::mock(ComposeWriter::class);
        $writer->shouldReceive('mergeAccountEnv')->once()->with($vars, $replaced);
        $writer->shouldReceive('mergeApiAccessEnv')->once()->andReturn(false);

        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('applyProjectEnvVars')->once();
        $runtime->shouldReceive('composeWriter')->twice()->andReturn($writer);
        $runtime->shouldReceive('system')->andReturn(new RecordingSystem());
        $runtime->shouldReceive('projectAction')->once()->with('up')->andReturn([
            'stdout' => '',
            'stderr' => $exitCode === 0 ? '' : 'compose up boom',
            'exit_code' => $exitCode,
        ]);

        return $runtime;
    }

    private function bindRuntime(User $user, Dind $runtime): void
    {
        $project = new class (new System(), $user, $runtime) extends ProjectAggregate {
            public function __construct(System $system, User $model, private Runtime $fake)
            {
                parent::__construct($system, $model);
            }

            public function runtime(): Runtime
            {
                return $this->fake;
            }
        };
        $system = new class ($project) extends System {
            public function __construct(private ProjectAggregate $project)
            {
            }

            public function project(User $model): ProjectAggregate
            {
                return $this->project;
            }
        };

        $this->app->instance(System::class, $system);
    }
}
