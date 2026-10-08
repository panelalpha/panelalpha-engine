<?php

namespace Tests\Unit\Task;

use App\Jobs\CreateBackup;
use App\Jobs\DeployProject;
use App\Lib\Task\TaskPruner;
use App\Models\Task;
use App\Models\TaskLog;
use InvalidArgumentException;

class TaskPrunerTest extends SqliteTaskTestCase
{
    public function test_keeps_newest_terminal_deploys_per_username_and_drops_older(): void
    {
        $kept = [];
        for ($i = 0; $i < 5; $i++) {
            $kept[] = $this->terminalTask(DeployProject::class, 'alice')->id;
        }
        $dropped = $kept[0];
        $this->terminalTask(DeployProject::class, 'bob');

        $result = TaskPruner::prune(keepDeploy: 4, keepOther: 10);

        $this->assertSame(1, $result['deleted_tasks']);
        $this->assertNull(Task::find($dropped));
        $this->assertSame(4, Task::query()->where('username', 'alice')->count());
        $this->assertSame(1, Task::query()->where('username', 'bob')->count());
    }

    public function test_backup_keep_is_independent_of_deploy_keep(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->terminalTask(DeployProject::class, 'alice');
        }
        $oldBackup = $this->terminalTask(CreateBackup::class, 'alice')->id;
        for ($i = 0; $i < 2; $i++) {
            $this->terminalTask(CreateBackup::class, 'alice');
        }

        TaskPruner::prune(keepDeploy: 50, keepOther: 2);

        $this->assertNull(Task::find($oldBackup));
        $this->assertSame(4, Task::query()->where('job_type', DeployProject::class)->count());
        $this->assertSame(2, Task::query()->where('job_type', CreateBackup::class)->count());
    }

    public function test_null_username_is_its_own_bucket(): void
    {
        $old = $this->terminalTask(CreateBackup::class, null)->id;
        for ($i = 0; $i < 2; $i++) {
            $this->terminalTask(CreateBackup::class, null);
        }
        $this->terminalTask(CreateBackup::class, 'alice');

        TaskPruner::prune(keepDeploy: 50, keepOther: 2);

        $this->assertNull(Task::find($old));
        $this->assertSame(2, Task::query()->whereNull('username')->count());
        $this->assertSame(1, Task::query()->where('username', 'alice')->count());
    }

    public function test_queued_and_running_are_never_deleted_or_trimmed(): void
    {
        $queued = Task::start(jobType: DeployProject::class, queue: 'default', username: 'alice');
        $running = Task::start(jobType: DeployProject::class, queue: 'default', username: 'alice');
        $running->markRunning();
        $this->addLine($running, 'dim', 'build');
        for ($i = 0; $i < 3; $i++) {
            $this->terminalTask(DeployProject::class, 'alice');
        }

        $result = TaskPruner::prune(keepDeploy: 1, keepOther: 1);

        $this->assertSame(2, $result['deleted_tasks']);
        $this->assertNotNull(Task::find($queued->id));
        $this->assertNotNull(Task::find($running->id));
        $this->assertSame(1, TaskLog::query()->where('task_id', $running->id)->count());
    }

    public function test_trim_drops_dim_and_caps_the_tail(): void
    {
        $task = $this->terminalTask(DeployProject::class, 'alice');
        $this->addLine($task, 'info', 'start');
        $this->addLine($task, 'dim', 'npm notice');
        $this->addLine($task, 'info', 'old info');
        $this->addLine($task, 'warn', 'kept warn');
        $this->addLine($task, 'error', 'kept error');
        TaskLog::create(['task_id' => $task->id, 'log' => 'plain line']);

        $deleted = TaskPruner::trimTask($task, maxLines: 3);

        $this->assertSame(3, $deleted);
        $msgs = TaskLog::query()->where('task_id', $task->id)->orderBy('id')->pluck('log')->all();
        $this->assertCount(3, $msgs);
        $this->assertStringContainsString('kept warn', $msgs[0]);
        $this->assertStringContainsString('kept error', $msgs[1]);
        $this->assertSame('plain line', $msgs[2]);
    }

    public function test_trim_is_noop_on_running_tasks(): void
    {
        $task = Task::start(jobType: DeployProject::class, queue: 'default', username: 'alice');
        $task->markRunning();
        $this->addLine($task, 'dim', 'still live');

        $this->assertSame(0, TaskPruner::trimTask($task));
        $this->assertSame(1, TaskLog::count());
    }

    public function test_prune_trims_kept_tasks_and_cascades_logs_of_deleted_ones(): void
    {
        $old = $this->terminalTask(DeployProject::class, 'alice');
        $this->addLine($old, 'info', 'gone');
        $kept = $this->terminalTask(DeployProject::class, 'alice');
        $this->addLine($kept, 'dim', 'spam');
        $this->addLine($kept, 'info', 'ok');

        $result = TaskPruner::prune(keepDeploy: 1, keepOther: 10);

        $this->assertSame(1, $result['deleted_tasks']);
        $this->assertSame(1, $result['trimmed_logs']);
        $this->assertNull(Task::find($old->id));
        $this->assertSame(0, TaskLog::query()->where('task_id', $old->id)->count());
        $this->assertSame(1, TaskLog::query()->where('task_id', $kept->id)->count());
    }

    public function test_username_filter_does_not_touch_other_projects(): void
    {
        $aliceOld = $this->terminalTask(DeployProject::class, 'alice')->id;
        $this->terminalTask(DeployProject::class, 'alice');
        $bob = $this->terminalTask(DeployProject::class, 'bob')->id;
        $this->terminalTask(DeployProject::class, 'bob');

        TaskPruner::prune(username: 'alice', keepDeploy: 1, keepOther: 10);

        $this->assertNull(Task::find($aliceOld));
        $this->assertNotNull(Task::find($bob));
        $this->assertSame(2, Task::query()->where('username', 'bob')->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->terminalTask(DeployProject::class, 'alice');
        $this->addLine($old, 'dim', 'spam');
        $kept = $this->terminalTask(DeployProject::class, 'alice');
        $this->addLine($kept, 'dim', 'spam');

        $result = TaskPruner::prune(keepDeploy: 1, keepOther: 10, dryRun: true);

        $this->assertSame(1, $result['deleted_tasks']);
        $this->assertSame(1, $result['trimmed_logs']);
        $this->assertSame(2, Task::count());
        $this->assertSame(2, TaskLog::count());
    }

    public function test_rejects_non_positive_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskPruner::prune(keepDeploy: 0);
    }

    public function test_artisan_command_prunes(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->terminalTask(DeployProject::class, 'alice');
        }

        $this->artisan('task:prune', [
            '--keep' => 2,
            '--keep-other' => 10,
            '--project' => 'alice',
        ])->assertExitCode(0);

        $this->assertSame(2, Task::count());
    }

    private function terminalTask(string $jobType, ?string $username): Task
    {
        $task = Task::start(jobType: $jobType, queue: 'default', username: $username);
        $task->markRunning();
        $task->markCompleted();

        return $task;
    }

    private function addLine(Task $task, string $level, string $msg): TaskLog
    {
        return TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode([
                'ts' => time(),
                'stage' => 'running',
                'level' => $level,
                'msg' => $msg,
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }
}
