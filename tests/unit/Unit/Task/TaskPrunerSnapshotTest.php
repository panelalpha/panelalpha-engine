<?php

namespace Tests\Unit\Task;

use App\Jobs\DeployProject;
use App\Lib\Task\TaskPruner;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\TaskSchema;
use Tests\TestCase;

/**
 * On SQLite in WAL mode an open cursor pins the read snapshot, so a commit by another
 * process during the sweep must not stop the next log trim with "database is locked".
 */
class TaskPrunerSnapshotTest extends TestCase
{
    private const OTHER_PROCESS = 'pruner_other';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'pruner') . '.sqlite';
        touch($this->file);
        foreach (['sqlite', self::OTHER_PROCESS] as $name) {
            config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'busy_timeout' => 100]]);
            DB::purge($name);
        }
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
        DB::statement('PRAGMA journal_mode = wal');
        TaskSchema::migrate();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge(self::OTHER_PROCESS);
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file . $suffix);
        }
        parent::tearDown();
    }

    public function test_every_task_is_trimmed_when_another_process_writes_during_the_sweep(): void
    {
        $tasks = [$this->taskWithDimLine(), $this->taskWithDimLine(), $this->taskWithDimLine()];
        DB::listen(function (QueryExecuted $query): void {
            if ($query->connectionName === 'sqlite' && str_starts_with($query->sql, 'delete from "task_logs"')) {
                $this->writeAsAnotherProcess();
            }
        });

        $result = TaskPruner::prune();

        $this->assertSame(3, $result['trimmed_logs']);
        foreach ($tasks as $task) {
            $this->assertSame(0, TaskLog::query()->where('task_id', $task->id)->count());
        }
    }

    private function taskWithDimLine(): Task
    {
        $task = Task::start(jobType: DeployProject::class, queue: 'default', username: 'alice');
        $task->markRunning();
        $task->markCompleted();
        TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode(['ts' => time(), 'stage' => 'running', 'level' => 'dim', 'msg' => 'npm notice']),
        ]);

        return $task;
    }

    private function writeAsAnotherProcess(): void
    {
        DB::connection(self::OTHER_PROCESS)->table('tasks')->where('id', 1)->update(['updated_at' => now()->toDateTimeString('microsecond')]);
    }
}
