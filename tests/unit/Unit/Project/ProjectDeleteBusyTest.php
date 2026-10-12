<?php

namespace Tests\Unit\Project;

use App\Exceptions\ProjectBusyException;
use App\Jobs\ApplyEnvVars;
use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The refusal as each delete path gives it: REST (and MCP project_delete,
 * which dispatches the same route) answers 409, `project:delete` prints a
 * plain line and exits 1. Neither touches the project.
 */
class ProjectDeleteBusyTest extends TestCase
{
    use InMemoryDatabase;

    private string $name;

    private Task $deploy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();

        $this->name = 'del' . bin2hex(random_bytes(4));
        $this->beforeApplicationDestroyed(fn () => DeployLogger::deleteUserLogs($this->name));
        $this->makeUser($this->name);
        $this->deploy = Task::start(jobType: DeployProject::class, queue: 'default', username: $this->name);
        $this->deploy->markRunning('job-1');
    }

    public function test_rest_answers_409_naming_the_running_task(): void
    {
        $response = $this->deleteJson("/api/projects/{$this->name}");

        $response->assertStatus(409);
        $response->assertExactJson([
            'message' => "Project '{$this->name}' is busy: deploy task {$this->deploy->id} is running. "
                . "Cancel it (POST /tasks/{$this->deploy->id}/cancel) or wait for it to finish.",
        ]);
        $this->assertTrue(User::existsByUsername($this->name));
    }

    /**
     * A running env var apply holds the account's deploy lock, so a delete
     * refuses rather than tear the account down under it.
     */
    public function test_a_running_env_apply_makes_a_delete_refuse(): void
    {
        $name = $this->name . 'ev';
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($name));
        $this->makeUser($name);
        $task = Task::start(jobType: ApplyEnvVars::class, queue: 'default', username: $name, details: [
            'action' => 'env_apply',
            'keys' => [],
        ]);
        $task->markRunning('job-env');
        $lock = DeployLock::acquireFor($name, DeployLock::HOLDER_ENV_VARS);

        try {
            $response = $this->deleteJson("/api/projects/{$name}");
        } finally {
            $lock->release();
        }

        $response->assertStatus(409);
        $this->assertTrue(User::existsByUsername($name));
    }

    public function test_a_refusal_is_not_logged_as_an_error(): void
    {
        $this->assertFalse(app(ExceptionHandler::class)->shouldReport(ProjectBusyException::lockHeld($this->name)));
    }

    /** What a rebuild asked for during a delete gets: the delete holds the account's lock, so nothing is queued. */
    public function test_a_rebuild_while_a_delete_holds_the_lock_answers_409_and_queues_nothing(): void
    {
        $name = $this->name . 't';
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($name));
        $this->makeUser($name, ['template' => 'wordpress']);
        $delete = DeployLock::acquireFor($name);

        try {
            $response = $this->postJson("/api/projects/{$name}/rebuild");
        } finally {
            $delete->release();
        }

        $response->assertStatus(409);
        $response->assertExactJson([
            'message' => "A deploy of this project is already running. Follow it with GET /projects/{$name}/deploy-log.",
            'task_id' => null,
        ]);
        $this->assertSame(0, Task::query()->where('username', $name)->count());
    }

    /**
     * A rebuild queued just before the delete took the lock: a template rebuild
     * meets the lock inside the rebuild, not when its log is opened, and still
     * fails as a lock conflict rather than as a failed rebuild.
     */
    public function test_a_queued_template_rebuild_run_during_a_delete_fails_as_a_lock_conflict(): void
    {
        $name = $this->name . 'q';
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($name));
        $this->makeUser($name, ['template' => 'wordpress']);
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $name, details: [
            'username' => $name,
            'action' => ProjectRebuild::REBUILD,
        ]);
        $job = (new RebuildProject($name))->attachTask($task);
        $delete = DeployLock::acquireFor($name);

        try {
            $this->app->call([$job, 'handle']);
        } catch (\Throwable) {
            // runTask() has recorded it on the task.
        } finally {
            $delete->release();
        }

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('deploy_already_running', $task->details['problems'][0]['code'] ?? null);
        $this->assertSame('A deployment is already running for this user.', $task->details['error'] ?? null);
    }

    /**
     * engine#840: a backup or restore stops the stack and swaps ~/project
     * under the deploy lock. A rebuild queued beside one must be refused
     * before it runs inside that window, naming the backup task.
     */
    public function test_a_queued_backup_makes_a_rebuild_refuse(): void
    {
        $name = $this->name . 'bk';
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($name));
        $this->makeUser($name);
        $backupTask = Task::start(jobType: \App\Jobs\CreateBackup::class, queue: 'default', username: $name, details: [
            'backup_id' => 1,
            'action' => 'backup',
        ]);

        $rebuild = app(ProjectRebuild::class);

        try {
            $this->expectException(ProjectBusyException::class);
            $rebuild->assertIdle(User::findByUsername($name));
        } finally {
            $backupTask->delete();
        }
    }

    /**
     * engine#840: a create/rebuild task still takes precedence in the message,
     * so a deploy that is actually running is what a client is told to cancel.
     */
    public function test_a_deploy_task_is_named_over_a_backup_task(): void
    {
        $name = $this->name . 'bo';
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($name));
        $this->makeUser($name);
        Task::start(jobType: \App\Jobs\CreateBackup::class, queue: 'default', username: $name, details: ['backup_id' => 1]);

        $rebuild = app(ProjectRebuild::class);
        $task = $rebuild->pendingTask($name);

        // pendingTask() is the deploy-lock subset, so it does not see the backup...
        $this->assertNull($task);
        // ...but assertIdle() refuses because of it.
        $this->expectException(ProjectBusyException::class);
        $rebuild->assertIdle(User::findByUsername($name));
    }

    public function test_the_cli_prints_a_plain_line_and_exits_1(): void
    {
        $this->artisan('project:delete', ['project' => [$this->name], '--force' => true])
            ->expectsOutput("Project '{$this->name}' is busy: deploy task {$this->deploy->id} is running. "
                . "Cancel task {$this->deploy->id} or wait for it to finish.")
            ->assertExitCode(1);

        $this->assertTrue(User::existsByUsername($this->name));
    }
}
