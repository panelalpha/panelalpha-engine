<?php

namespace Tests\Integration\Jobs;

use App\Jobs\ApplyEnvVars;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Runtime;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * The env var apply where it does not get as far as a compose up, where the up
 * fails without saying why, and what its failure handler marks.
 */
class ApplyEnvVarsOutcomesTest extends TestCase
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

    public function test_a_project_deleted_since_it_was_queued_cancels_the_task(): void
    {
        $task = $this->task('envgone');

        (new ApplyEnvVars('envgone'))->attachTask($task)->handle();

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    public function test_a_classic_project_found_by_name_is_refused(): void
    {
        $user = $this->makeUser('envclassic' . bin2hex(random_bytes(3)));
        $task = $this->task($user->username);

        try {
            (new ApplyEnvVars($user->username))->attachTask($task)->handle();
            $this->fail('The job did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame("Project '{$user->username}' is not a container project.", $e->getMessage());
        }

        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }

    public function test_a_compose_up_failing_without_output_says_the_application_did_not_start(): void
    {
        $user = $this->makeUser('envsilent' . bin2hex(random_bytes(3)), ['template' => 'dind']);
        $this->bindRuntime($user, $this->runtimeAnswering(['stdout' => '', 'stderr' => "  \n", 'exit_code' => 1]));
        $task = $this->task($user->username);

        try {
            (new ApplyEnvVars($user->username, [], (int) $user->id))->attachTask($task)->handle();
            $this->fail('The job did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The application could not be started with the new environment variables.', $e->getMessage());
        } finally {
            DeployLogger::deleteUserLogs($user->username);
        }

        $this->assertSame('The application could not be started with the new environment variables.', $task->refresh()->details['error']);
    }

    public function test_a_held_lock_without_a_task_fails_without_recording_a_problem(): void
    {
        $user = $this->makeUser('envlocked' . bin2hex(random_bytes(3)), ['template' => 'dind']);
        $this->bindRuntime($user, Mockery::mock(Dind::class));
        $lock = DeployLock::acquireFor($user->username);

        try {
            (new ApplyEnvVars($user->username, [], (int) $user->id))->handle();
            $this->fail('The job did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('A deployment is already running for this user.', $e->getMessage());
        } finally {
            $lock->release();
            DeployLogger::deleteUserLogs($user->username);
        }

        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_failed_job_fails_its_task_and_marks_the_pending_token_replacement_failed(): void
    {
        $user = $this->makeUser('envfail' . bin2hex(random_bytes(3)), ['template' => 'dind']);
        Setting::set(ApiAccess::SETTING, (string) json_encode([[
            'project' => $user->username,
            'scope' => ApiAccess::SCOPE_SELF,
            'token_id' => 7,
            'pending_revoke_token_id' => 6,
        ]]));
        $task = $this->task($user->username);

        (new ApplyEnvVars($user->username, [], (int) $user->id))->attachTask($task)->failed(null);

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Apply env vars job failed', $task->details['error']);
        Setting::flushCache();
        $entry = json_decode((string) Setting::get(ApiAccess::SETTING), true)[0];
        $this->assertTrue($entry['pending_revoke_failed']);
    }

    private function task(string $username): Task
    {
        return Task::start(jobType: ApplyEnvVars::class, queue: 'default', username: $username);
    }

    /** @param array{stdout: string, stderr: string, exit_code: int} $result */
    private function runtimeAnswering(array $result): Dind
    {
        $writer = Mockery::mock(ComposeWriter::class);
        $writer->shouldReceive('mergeAccountEnv')->once();
        $writer->shouldReceive('mergeApiAccessEnv')->once()->andReturn(false);

        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('applyProjectEnvVars')->once();
        $runtime->shouldReceive('composeWriter')->andReturn($writer);
        $runtime->shouldReceive('system')->andReturn(new RecordingSystem());
        $runtime->shouldReceive('projectAction')->once()->with('up')->andReturn($result);

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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return FakeProcess::ok();
            }
        };

        $this->app->instance(System::class, $system);
    }
}
