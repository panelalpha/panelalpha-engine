<?php

namespace Tests\Unit\Task;

use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Models\Task;
use Mockery;

class TaskCancellerTest extends SqliteTaskTestCase
{
    public function test_cancel_on_terminal_task_is_rejected(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markCompleted();
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        $result = (new TaskCanceller($killer))->cancel($task);

        $this->assertFalse($result['cancelled']);
        $this->assertSame('Task cannot be cancelled', $result['message']);
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_cancel_on_queued_task_marks_cancelled_without_kill(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        $result = (new TaskCanceller($killer))->cancel($task);

        $this->assertTrue($result['cancelled']);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertNotNull($task->cancelled_at);
    }

    public function test_cancel_queued_cancels_only_a_task_no_worker_has_taken(): void
    {
        $queued = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $running = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $running->markRunning('h-1');
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');
        $canceller = new TaskCanceller($killer);

        $this->assertTrue($canceller->cancelQueued($queued));
        $this->assertFalse($canceller->cancelQueued($running));

        $this->assertSame(Task::STATUS_CANCELLED, $queued->refresh()->status);
        $this->assertSame(Task::STATUS_RUNNING, $running->refresh()->status);
    }

    /** engine#794: a cancel racing a finishing task must not report success. */
    public function test_a_cancel_that_loses_to_a_finishing_worker_is_refused(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markRunning('h-1');
        $cancelCopy = Task::findOrFail($task->id);
        // The worker finishes it after the controller read it as running.
        $task->markCompleted();

        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        $result = (new TaskCanceller($killer))->cancel($cancelCopy);

        $this->assertFalse($result['cancelled']);
        $this->assertSame(Task::STATUS_COMPLETED, $cancelCopy->refresh()->status);
        $this->assertNull($cancelCopy->cancelled_at);
    }

    /**
     * engine#793: a running backup/restore/staging job sets no pid and checks
     * for cancellation nowhere, so it cannot be stopped. Marking it cancelled
     * answered 200 while nothing was killed and the job ran to the end.
     */
    public function test_cancelling_a_running_non_deploy_job_is_refused(): void
    {
        $task = Task::start(jobType: \App\Jobs\CreateBackup::class, queue: 'default');
        $task->markRunning('h-1');

        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        $result = (new TaskCanceller($killer))->cancel($task);

        $this->assertFalse($result['cancelled']);
        $this->assertStringContainsString('cannot be stopped', $result['message']);
        $this->assertSame(Task::STATUS_RUNNING, $task->refresh()->status);
        $this->assertNull($task->cancelled_at);
    }

    public function test_cancel_does_not_kill_when_pid_is_no_longer_the_same_process(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markRunning('h-1');
        $task->setPid(99999, '1');
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        $result = (new TaskCanceller($killer))->cancel($task);

        $this->assertTrue($result['cancelled']);
    }

    public function test_cancel_kills_when_process_identity_matches(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markRunning('h-1');
        $task->setPid(4242, '99');
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldReceive('kill')->once()->with(4242);

        $isStillRunning = function ($pid, $startTime): bool {
            return $pid === 4242 && $startTime === '99';
        };

        $result = (new TaskCanceller($killer, $isStillRunning))->cancel($task);

        $this->assertTrue($result['cancelled']);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    /** A deploy the task API cancels leaves what it started behind unless its app stack is torn down. */
    public function test_cancelling_a_running_deploy_tears_down_its_half_started_app(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username());
        $task->markRunning('h-1');
        $task->setPid(4242, '99');
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldReceive('kill')->once()->with(4242);
        $torn = [];
        $canceller = new TaskCanceller($killer, fn () => true, function (Task $cancelled) use (&$torn): void {
            $torn[] = $cancelled->id;
        });

        $result = $canceller->cancel($task);

        $this->assertTrue($result['cancelled']);
        $this->assertSame([$task->id], $torn);
    }

    /** A queued deploy started nothing, so cancelling it tears nothing down. */
    public function test_cancelling_a_queued_deploy_tears_down_nothing(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username());
        $torn = [];
        $canceller = new TaskCanceller($this->noKill(), fn () => true, function () use (&$torn): void {
            $torn[] = 'called';
        });

        $this->assertTrue($canceller->cancel($task)['cancelled']);
        $this->assertSame([], $torn);
    }

    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        gc_collect_cycles();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function username(): string
    {
        $username = 'cancel' . bin2hex(random_bytes(4));
        $this->usernames[] = $username;

        return $username;
    }

    private function noKill(): ProcessTreeKiller
    {
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        return $killer;
    }

    /** A create's log left waiting for files, started long before any rebuild of it. */
    private function waitingCreateLog(string $username): string
    {
        $create = DeployLogger::start($username);
        $create->stage(DeployLogger::STAGE_PREPARING);
        $create->info(DeployLogger::WAITING_FOR_FILES);
        $id = $create->getDeployId();
        unset($create);
        gc_collect_cycles();
        $status = new DeployStatus(new DeployLogPaths($username));
        $status->write(array_merge($status->read() ?? [], ['started_at' => time() - 3600]));

        return $id;
    }

    /** A queued task runs no deploy: one started without a task keeps running. */
    public function test_cancelling_a_queued_rebuild_leaves_another_deploy_alone(): void
    {
        $username = $this->username();
        $other = DeployLogger::start($username);
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $username);

        $this->assertTrue((new TaskCanceller($this->noKill()))->cancel($task)['cancelled']);

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status']);
        $other->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_cancelling_a_queued_rebuild_leaves_the_create_log_waiting(): void
    {
        $username = $this->username();
        $this->waitingCreateLog($username);
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $username);

        (new TaskCanceller($this->noKill()))->cancel($task);

        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status'], 'the next archive deploy still resumes it');
    }

    /** A running rebuild that resumed the create's log cancels that deploy: the log is its own by id. */
    public function test_cancelling_a_running_rebuild_cancels_the_deploy_it_resumed(): void
    {
        $username = $this->username();
        $id = $this->waitingCreateLog($username);
        $resumed = DeployLogger::resumeRunningOrStart($username);
        $this->assertSame($id, $resumed->getDeployId());
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $username);
        $task->markRunning('h-1');
        $task->details = ['deploy_id' => $id];
        $task->save();

        (new TaskCanceller($this->noKill()))->cancel($task);

        $this->assertSame(DeployLogger::STATUS_CANCELLED, DeployLogger::readLatestFor($username)['status']);
        unset($resumed);
    }

    public function test_a_running_task_does_not_cancel_a_deploy_that_is_not_its_own(): void
    {
        $username = $this->username();
        $other = DeployLogger::start($username);
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $username);
        $task->markRunning('h-1');
        $task->details = ['deploy_id' => 'some-earlier-deploy'];
        $task->save();

        (new TaskCanceller($this->noKill()))->cancel($task);

        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status']);
        $other->finish(DeployLogger::STATUS_SUCCESS);
    }
}
