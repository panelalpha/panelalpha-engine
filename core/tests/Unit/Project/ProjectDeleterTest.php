<?php

namespace Tests\Unit\Project;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateBackup;
use App\Jobs\CreateStaging;
use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\Lib\Project\ProjectDeleter;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Models\Task;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A delete never overlaps a job working on the account: queued jobs are
 * cancelled first, running ones refuse it, and the account's deploy lock is
 * held while the account is torn down.
 */
class ProjectDeleterTest extends TestCase
{
    use InMemoryDatabase;
    use MockeryPHPUnitIntegration;

    private string $name;

    /** @var list<string> what destroy() saw, one entry per call */
    private array $destroyed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        // The lock lives in the real deploy-log directory; a fresh name per test.
        $this->name = 'del' . bin2hex(random_bytes(4));
        $this->beforeApplicationDestroyed(fn () => DeployLogger::deleteUserLogs($this->name));
    }

    /**
     * @param null|callable(): void $during runs where the account would be torn down
     * @param null|callable(Task): ?bool $inQueue
     */
    private function deleter(?callable $during = null, ?callable $inQueue = null, ?TaskCanceller $canceller = null): ProjectDeleter
    {
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldNotReceive('kill');

        $deleter = new class ($canceller ?? new TaskCanceller($killer), $inQueue ?? static fn (): bool => false) extends ProjectDeleter {
            /** @var callable(User): void */
            public $onDestroy;

            protected function destroy(User $user): void
            {
                ($this->onDestroy)($user);
            }
        };
        $deleter->onDestroy = function (User $user) use ($during): void {
            $this->destroyed[] = $user->username;
            if ($during !== null) {
                $during();
            }
        };

        return $deleter;
    }

    private function task(string $jobType, string $username, array $details = []): Task
    {
        return Task::start(jobType: $jobType, queue: 'default', username: $username, details: $details);
    }

    private function running(Task $task, string $jobId = 'job-1'): Task
    {
        $this->assertTrue($task->markRunning($jobId));

        return $task;
    }

    /** The worker that took the task, as AttachTask records it; a start time of '0' is a worker that died. */
    private function takenBy(Task $task, string $start): Task
    {
        $task->details = array_merge($task->details ?? [], [Task::WORKER => ['pid' => getmypid(), 'start' => $start]]);
        $task->save();

        return $task;
    }

    private function refusal(callable $delete): ProjectBusyException
    {
        try {
            $delete();
        } catch (ProjectBusyException $e) {
            return $e;
        }
        $this->fail('The delete was not refused.');
    }

    public function test_queued_tasks_are_cancelled_before_the_account_is_torn_down(): void
    {
        $user = $this->makeUser($this->name);
        $deploy = $this->task(DeployProject::class, $this->name);
        $backup = $this->task(CreateBackup::class, $this->name);
        $statuses = [];

        $this->deleter(function () use ($deploy, $backup, &$statuses): void {
            $statuses = [$deploy->refresh()->status, $backup->refresh()->status];
        })->delete($user);

        $this->assertSame([$this->name], $this->destroyed);
        $this->assertSame([Task::STATUS_CANCELLED, Task::STATUS_CANCELLED], $statuses);
    }

    public function test_the_job_of_a_cancelled_queued_task_does_nothing_when_a_worker_takes_it(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->task(DeployProject::class, $this->name);
        $this->deleter()->delete($user);

        // Reaching the project would throw: its row is still here, and runDeployment() is real.
        (new DeployProject($this->name, userId: (int) $user->id))->attachTask($task)->handle();

        $task->refresh();
        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertNull($task->started_at);
    }

    public function test_a_running_deploy_refuses_the_delete_and_names_its_task(): void
    {
        $user = $this->makeUser($this->name);
        $deploy = $this->running($this->task(DeployProject::class, $this->name));
        $queued = $this->task(CreateBackup::class, $this->name);

        $e = $this->refusal(fn () => $this->deleter()->delete($user));

        $this->assertSame(409, $e->getCode());
        $this->assertSame(
            "Project '{$this->name}' is busy: deploy task {$deploy->id} is running. "
            . "Cancel it (POST /tasks/{$deploy->id}/cancel) or wait for it to finish.",
            $e->getMessage(),
        );
        $this->assertSame([], $this->destroyed);
        $this->assertSame(Task::STATUS_QUEUED, $queued->refresh()->status, 'a refused delete changes nothing');
    }

    public function test_the_cli_refusal_names_no_endpoint(): void
    {
        $user = $this->makeUser($this->name);
        $deploy = $this->running($this->task(DeployProject::class, $this->name));

        $e = $this->refusal(fn () => $this->deleter()->delete($user));

        $this->assertSame(
            "Project '{$this->name}' is busy: deploy task {$deploy->id} is running. Cancel task {$deploy->id} or wait for it to finish.",
            $e->plainMessage(),
        );
    }

    public function test_a_running_rebuild_or_archive_deploy_refuses_the_delete_and_is_named(): void
    {
        $user = $this->makeUser($this->name);
        $rebuild = $this->running($this->task(RebuildProject::class, $this->name, ['action' => ProjectRebuild::REBUILD]));

        $e = $this->refusal(fn () => $this->deleter()->delete($user));

        $this->assertSame(
            "Project '{$this->name}' is busy: rebuild task {$rebuild->id} is running. "
            . "Cancel it (POST /tasks/{$rebuild->id}/cancel) or wait for it to finish.",
            $e->getMessage(),
        );

        $rebuild->markCompleted();
        $archive = $this->running($this->task(RebuildProject::class, $this->name, ['action' => ProjectRebuild::DEPLOY_ARCHIVE]), 'job-2');

        $e = $this->refusal(fn () => $this->deleter()->delete($user));

        $this->assertStringStartsWith("Project '{$this->name}' is busy: archive deploy task {$archive->id} is running.", $e->getMessage());

        // A pull, branch change or revert queued with its rebuild.
        $archive->markCompleted();
        $named = [ProjectRebuild::GIT_PULL => 'git pull', ProjectRebuild::CHANGE_BRANCH => 'branch change', ProjectRebuild::REVERT => 'git revert'];
        foreach ($named as $action => $kind) {
            $git = $this->running($this->task(RebuildProject::class, $this->name, ['action' => $action]), "job-{$action}");

            $e = $this->refusal(fn () => $this->deleter()->delete($user));

            $this->assertStringStartsWith("Project '{$this->name}' is busy: {$kind} task {$git->id} is running.", $e->getMessage());
            $git->markCompleted();
        }
        $this->assertSame([], $this->destroyed);
    }

    /**
     * Its row reads running until the next task:reconcile, but nothing runs
     * it: the delete goes ahead instead of waiting for the sweep.
     */
    public function test_a_running_task_whose_worker_died_does_not_refuse_the_delete(): void
    {
        $pid = getmypid();
        if (!is_int($pid) || ProcessIdentity::startTime($pid) === null) {
            $this->markTestSkipped('/proc is required to tell a live worker from a dead one');
        }
        $user = $this->makeUser($this->name);
        $rebuild = $this->running($this->task(RebuildProject::class, $this->name, ['action' => ProjectRebuild::REBUILD]));

        $this->takenBy($rebuild, (string) ProcessIdentity::startTime($pid));
        $this->refusal(fn () => $this->deleter()->delete($user));
        $this->assertSame([], $this->destroyed, 'a live worker still refuses it');

        $this->takenBy($rebuild, '0');
        $this->deleter()->delete($user);

        $this->assertSame([$this->name], $this->destroyed);
    }

    /**
     * A dead worker's queue row stays reserved for a day, so a task cancelled
     * before or after its worker died would read as still stopping for the
     * whole two-hour window.
     */
    public function test_a_cancelled_task_whose_worker_died_does_not_refuse_the_delete(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->takenBy($this->running($this->task(RebuildProject::class, $this->name), 'job-reserved'), '0');
        $task->markCancelled();

        $this->deleter(inQueue: static fn (Task $t): bool => $t->job_id === 'job-reserved')->delete($user);

        $this->assertSame([$this->name], $this->destroyed);
    }

    /** With the lock held elsewhere, a build the dead worker started may be what holds it. */
    public function test_a_dead_workers_task_is_named_while_the_lock_is_held_elsewhere(): void
    {
        $user = $this->makeUser($this->name);
        $rebuild = $this->takenBy($this->running($this->task(RebuildProject::class, $this->name)), '0');
        $build = DeployLock::acquireFor($this->name);

        try {
            $e = $this->refusal(fn () => $this->deleter()->delete($user));
        } finally {
            $build->release();
        }

        $this->assertStringContainsString("rebuild task {$rebuild->id} is running", $e->getMessage());
        $this->assertSame([], $this->destroyed);
    }

    public function test_a_running_staging_copy_refuses_deleting_the_destination(): void
    {
        $source = $this->makeUser($this->name . 's');
        $dest = $this->makeUser($this->name);
        $copy = $this->running($this->task(CreateStaging::class, $dest->username, ['source' => $source->username, 'action' => 'staging']));

        $e = $this->refusal(fn () => $this->deleter()->delete($dest));

        $this->assertStringContainsString("staging copy task {$copy->id} is running", $e->getMessage());
        $this->assertSame([], $this->destroyed);
    }

    public function test_a_running_staging_copy_refuses_deleting_its_source(): void
    {
        $source = $this->makeUser($this->name);
        $copy = $this->running($this->task(CreateStaging::class, $this->name . 'stg', ['source' => $source->username, 'action' => 'staging']));

        $e = $this->refusal(fn () => $this->deleter()->delete($source));

        $this->assertSame(
            "Project '{$this->name}' is busy: staging copy task {$copy->id} is running. "
            . "Cancel it (POST /tasks/{$copy->id}/cancel) or wait for it to finish.",
            $e->getMessage(),
        );
        $this->assertSame([], $this->destroyed);
    }

    public function test_a_queued_staging_copy_is_cancelled_with_its_destination(): void
    {
        $source = $this->makeUser($this->name . 's');
        $dest = $this->makeUser($this->name);
        $copy = $this->task(CreateStaging::class, $dest->username, ['source' => $source->username, 'action' => 'staging']);

        $this->deleter()->delete($dest);

        $this->assertSame([$this->name], $this->destroyed);
        $this->assertSame(Task::STATUS_CANCELLED, $copy->refresh()->status);
    }

    public function test_a_push_refuses_deleting_either_project_of_the_pair(): void
    {
        $live = $this->makeUser($this->name);
        $staging = $this->makeUser($this->name . 'stg');
        $staging->staging = $live->id;
        $staging->save();
        $live->mergeAsyncStatus(['push' => 'running', 'source' => 'api']);
        $live->save();

        foreach ([$live->fresh(), $staging->fresh()] as $side) {
            $e = $this->refusal(fn () => $this->deleter()->delete($side));
            $this->assertSame("Project '{$side->username}' is busy: a push is running. Wait for it to finish.", $e->getMessage());
        }
        $this->assertSame([], $this->destroyed);
    }

    public function test_a_cancelled_task_whose_job_is_still_stopping_refuses_the_delete(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->running($this->task(DeployProject::class, $this->name), 'job-stopping');
        $task->markCancelled();

        $e = $this->refusal(fn () => $this->deleter(inQueue: static fn (Task $t): bool => $t->job_id === 'job-stopping')->delete($user));

        $this->assertSame(
            "Project '{$this->name}' is busy: deploy task {$task->id} was cancelled and is still stopping. Wait for it to finish.",
            $e->getMessage(),
        );
        $this->assertSame([], $this->destroyed);
    }

    public function test_once_the_cancelled_job_has_left_the_queue_the_delete_goes_ahead(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->running($this->task(DeployProject::class, $this->name));
        $task->markCancelled();

        $this->deleter(inQueue: static fn (): bool => false)->delete($user);

        $this->assertSame([$this->name], $this->destroyed);
    }

    public function test_a_task_a_worker_takes_between_the_check_and_the_cancel_refuses_the_delete(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->task(DeployProject::class, $this->name);
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $canceller = new class ($killer) extends TaskCanceller {
            public function cancelQueued(Task $task): bool
            {
                // The worker's write lands first.
                Task::query()->whereKey($task->id)->first()->markRunning('job-raced');

                return parent::cancelQueued($task);
            }
        };

        $e = $this->refusal(fn () => $this->deleter(canceller: $canceller)->delete($user));

        $this->assertStringContainsString("deploy task {$task->id} is running", $e->getMessage());
        $this->assertSame(Task::STATUS_RUNNING, $task->refresh()->status);
        $this->assertSame([], $this->destroyed);
    }

    public function test_a_deploy_holding_the_lock_without_a_task_refuses_the_delete(): void
    {
        $user = $this->makeUser($this->name);
        $deploy = DeployLock::acquireFor($this->name);

        try {
            $e = $this->refusal(fn () => $this->deleter()->delete($user));
        } finally {
            $deploy->release();
        }

        $this->assertSame("Project '{$this->name}' is busy: a deploy is running. Wait for it to finish.", $e->getMessage());
        $this->assertSame([], $this->destroyed);
    }

    public function test_the_account_lock_is_held_while_the_account_is_torn_down(): void
    {
        $user = $this->makeUser($this->name);
        $seen = [];

        $this->deleter(function () use (&$seen): void {
            $seen['locked'] = DeployLogger::isLockedFor($this->name);
            try {
                DeployLogger::start($this->name);
                $seen['deploy'] = 'started';
            } catch (DeployAlreadyRunningException) {
                $seen['deploy'] = 'refused';
            }
        })->delete($user);

        $this->assertSame(['locked' => true, 'deploy' => 'refused'], $seen);
        $this->assertFalse(DeployLogger::isLockedFor($this->name), 'released after the delete');
    }

    public function test_a_template_rebuild_arriving_during_the_delete_is_refused_before_it_touches_the_host(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'wordpress']);
        $refused = false;

        $this->deleter(function () use ($user, &$refused): void {
            try {
                $user->project()->rebuildFromSource();
            } catch (DeployAlreadyRunningException) {
                $refused = true;
            }
        })->delete($user);

        $this->assertTrue($refused);
    }

    public function test_a_refused_delete_leaves_no_lock_directory_behind(): void
    {
        $user = $this->makeUser($this->name);
        $this->running($this->task(DeployProject::class, $this->name));

        $this->refusal(fn () => $this->deleter()->delete($user));

        $this->assertDirectoryDoesNotExist(DeployLogger::userDirFor($this->name));
    }

    public function test_a_live_project_with_a_staging_copy_is_refused_before_anything_is_cancelled(): void
    {
        $live = $this->makeUser($this->name);
        $staging = $this->makeUser($this->name . 'stg');
        $staging->staging = $live->id;
        $staging->save();
        $queued = $this->task(CreateBackup::class, $this->name);

        try {
            $this->deleter()->delete($live);
            $this->fail('The delete was not refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Delete the staging project first.', $e->getMessage());
        }

        $this->assertSame(Task::STATUS_QUEUED, $queued->refresh()->status);
        $this->assertSame([], $this->destroyed);
    }
}
