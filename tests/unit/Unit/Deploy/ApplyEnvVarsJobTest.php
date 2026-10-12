<?php

namespace Tests\Unit\Deploy;

use App\Jobs\ApplyEnvVars;
use App\Lib\Deploy\DeployLog\DeployLock;
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

        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('applyProjectEnvVars')->once()->andReturnUsing(function () use ($user): void {
            $this->assertSame(DeployLock::HOLDER_ENV_VARS, DeployLock::heldBy($user->username));
        });
        $runtime->shouldReceive('composeWriter')->once()->andReturn($writer);
        $runtime->shouldReceive('projectAction')->once()->with('up')->andReturn([
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 0,
        ]);

        $task = $this->runJob($user, $runtime, []);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertNull(DeployLock::heldBy($user->username), 'the lock is released');
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

    private function runJob(User $user, Dind $runtime, array $replaced, ?string $expectedError = null): Task
    {
        $this->bindRuntime($user, $runtime);

        $task = Task::start(jobType: ApplyEnvVars::class, queue: 'default', username: $user->username, details: [
            'action' => 'env_apply',
            'keys' => array_keys($user->getEnvVars()),
        ]);
        $job = (new ApplyEnvVars($user->username, $replaced, (int) $user->getKey()))->attachTask($task);

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

        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('applyProjectEnvVars')->once();
        $runtime->shouldReceive('composeWriter')->once()->andReturn($writer);
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
