<?php

namespace Tests\Unit\Task;

use App\Http\Middleware\Authenticate;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\TaskReconciler;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A worker killed during a project's first redeploy. The redeploy resumed the
 * log the create left waiting for files, so that log started long before the
 * task; the killed job's queue row stays reserved for a day. The task read
 * `running` for that day, and every rebuild in it answered 409 with the dead
 * task's id. The minutely `task:reconcile` now retires it.
 */
class RebuildCrashRecoveryTest extends TestCase
{
    use InMemoryDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'queue.default' => 'database']);
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        $this->user = $this->makeUser('crash' . bin2hex(random_bytes(3)), ['template' => 'dind']);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->user->username);
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    private function rebuild(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/projects/{$this->user->username}/rebuild");
    }

    /** The create finished an hour ago, its log left `running`, waiting for files, with no lock. */
    private function createWaitingForFiles(): string
    {
        $create = DeployLogger::start($this->user->username);
        $create->stage(DeployLogger::STAGE_PREPARING);
        $create->info(DeployLogger::WAITING_FOR_FILES);
        $id = $create->getDeployId();
        unset($create);
        gc_collect_cycles();
        $status = new DeployStatus(new DeployLogPaths($this->user->username));
        $status->write(array_merge($status->read() ?? [], ['started_at' => time() - 3600]));

        return $id;
    }

    /**
     * A worker reserves the queued job and starts it, then is killed: its queue
     * row stays reserved, and its process is gone.
     */
    private function workerTakesItAndDies(int $taskId, ?\Closure $before = null): Task
    {
        $row = DB::table('jobs')->orderBy('id')->first();
        DB::table('jobs')->where('id', $row->id)->update(['reserved_at' => time(), 'attempts' => 1]);

        $job = new RebuildProject($this->user->username);
        $job->taskId = $taskId;
        $job->markRunning();
        $task = Task::findOrFail($taskId);
        $task->job_id = (string) json_decode((string) $row->payload, true)['uuid'];
        $task->started_at = now()->subMinutes(5);
        $task->save();

        if ($before !== null) {
            $before($task);
        }

        // kill -9: the recorded worker is no longer that process.
        $task->refresh();
        $task->details = array_merge($task->details ?? [], [Task::WORKER => ['pid' => getmypid(), 'start' => '0']]);
        $task->save();
        gc_collect_cycles();

        return $task->refresh();
    }

    public function test_a_worker_killed_mid_first_redeploy_blocks_rebuilds_only_until_the_sweep(): void
    {
        $createId = $this->createWaitingForFiles();
        $queued = $this->rebuild();
        $this->assertSame(202, $queued->getStatusCode());

        $task = $this->workerTakesItAndDies((int) $queued->json('data.id'), function (Task $task) use ($createId): void {
            // What the job does before the pipeline gets far: resume the create's log, record it.
            $logger = app(ProjectRebuild::class)->openLog($this->user, ProjectRebuild::REBUILD);
            $this->assertSame($createId, $logger->getDeployId(), 'the first redeploy continues the create log');
            $task->details = array_merge($task->details ?? [], ['deploy_id' => $logger->getDeployId()]);
            $task->save();
            $logger->info('Continuing deploy with project files');
            $logger->stage(DeployLogger::STAGE_CLONING);
        });

        // The reviewer's evidence: the queue still holds the job, and the log started an hour before the task.
        $this->assertTrue(TaskReconciler::queueCheck()($task));
        $this->assertLessThan($task->started_at->getTimestamp() - 60, DeployLogger::readLatestFor($this->user->username)['started_at']);

        $blocked = $this->rebuild();
        $this->assertSame(409, $blocked->getStatusCode());
        $blocked->assertJsonPath('task_id', $task->id);

        $this->assertSame(0, Artisan::call('task:reconcile', ['--older-than' => 120]));

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $task->details['error']);
        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::readLatestFor($this->user->username)['status']);
        $this->assertSame(202, $this->rebuild()->getStatusCode());
    }

    /**
     * A child the killed worker started (a build) inherited the deploy lock
     * and still runs: the task waits for it, then fails with the log's verdict.
     */
    public function test_a_build_the_dead_worker_left_running_is_waited_for(): void
    {
        $this->createWaitingForFiles();
        $queued = $this->rebuild();
        $child = null;
        $task = $this->workerTakesItAndDies((int) $queued->json('data.id'), function (Task $task) use (&$child): void {
            $child = app(ProjectRebuild::class)->openLog($this->user, ProjectRebuild::REBUILD);
            $task->details = array_merge($task->details ?? [], ['deploy_id' => $child->getDeployId()]);
            $task->save();
            $child->info('Continuing deploy with project files');
            $child->stage(DeployLogger::STAGE_RUNNING);
        });

        Artisan::call('task:reconcile', ['--older-than' => 120]);
        $this->assertSame(Task::STATUS_RUNNING, $task->refresh()->status, 'something still deploys');
        $this->assertSame(409, $this->rebuild()->getStatusCode());

        unset($child);
        gc_collect_cycles();
        Artisan::call('task:reconcile', ['--older-than' => 120]);

        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $task->details['error']);
        $this->assertSame(202, $this->rebuild()->getStatusCode());
    }

    /** Killed before it opened the log: nothing was deployed, and the create's log still waits. */
    public function test_a_worker_killed_before_it_opened_the_log_is_retired_too(): void
    {
        $createId = $this->createWaitingForFiles();
        $queued = $this->rebuild();
        $task = $this->workerTakesItAndDies((int) $queued->json('data.id'));
        $this->assertSame(409, $this->rebuild()->getStatusCode());

        Artisan::call('task:reconcile', ['--older-than' => 120]);

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertSame(TaskReconciler::ORPHAN_MESSAGE, $task->details['reconciled']);
        $latest = DeployLogger::readLatestFor($this->user->username);
        $this->assertSame([$createId, DeployLogger::STATUS_RUNNING], [$latest['id'], $latest['status']], 'still waiting for files');
        $this->assertSame(202, $this->rebuild()->getStatusCode());
    }

    /** A template project's rebuild writes no deploy log at all. */
    public function test_a_template_rebuild_whose_worker_died_is_retired(): void
    {
        $this->user->setDetails(['template' => 'default']);
        $this->user->save();
        $queued = $this->rebuild();
        $task = $this->workerTakesItAndDies((int) $queued->json('data.id'));

        Artisan::call('task:reconcile', ['--older-than' => 120]);

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertSame(202, $this->rebuild()->getStatusCode());
    }

    /** The same sweep leaves a rebuild alone while its worker is alive. */
    public function test_a_rebuild_whose_worker_is_alive_is_left_running(): void
    {
        $queued = $this->rebuild();
        $row = DB::table('jobs')->orderBy('id')->first();
        DB::table('jobs')->where('id', $row->id)->update(['reserved_at' => time(), 'attempts' => 1]);
        $job = new RebuildProject($this->user->username);
        $job->taskId = (int) $queued->json('data.id');
        $job->markRunning();
        $task = Task::findOrFail($job->taskId);
        $task->job_id = (string) json_decode((string) $row->payload, true)['uuid'];
        $task->started_at = now()->subMinutes(5);
        $task->save();

        Artisan::call('task:reconcile', ['--older-than' => 120]);

        $this->assertSame(Task::STATUS_RUNNING, $task->refresh()->status);
        $this->assertSame(409, $this->rebuild()->getStatusCode());
    }
}
