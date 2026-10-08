<?php

namespace Tests\Unit\Task;

use App\Jobs\CreateStaging;
use App\Lib\Task\TaskReconciler;
use App\Models\Task;
use App\Models\User;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A task retired because its worker died must take the killed job's reserved
 * queue row with it. Left there, a worker runs the job's failed() a day later
 * at retry_after, and CreateStaging's destroys the staging project by name --
 * by then possibly a new one the user created after the task said cancelled.
 */
class DeadWorkerQueueRowTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $failed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['queue.default' => 'database', 'queue.failed.database' => 'sqlite']);
        Queue::failing(function (JobFailed $event): void {
            $this->failed[] = $event->job->resolveName();
        });
    }

    /** Queued, then reserved by a worker that was killed: its row stays reserved. */
    private function reservedStagingJob(string $dest, bool $withTask = true): ?Task
    {
        $task = $withTask ? Task::start(jobType: CreateStaging::class, queue: 'default', username: $dest) : null;
        $pending = CreateStaging::dispatch($dest);
        if ($task !== null) {
            $pending->attachTask($task);
        }
        unset($pending);

        $row = DB::table('jobs')->orderByDesc('id')->first();
        DB::table('jobs')->where('id', $row->id)->update(['reserved_at' => time(), 'attempts' => 1]);
        if ($task === null) {
            return null;
        }

        $task->refresh()->markRunning((string) json_decode((string) $row->payload, true)['uuid']);
        $task->started_at = now()->subMinutes(5);
        $task->details = array_merge($task->details ?? [], [Task::WORKER => ['pid' => getmypid(), 'start' => '0']]);
        $task->save();

        return $task->refresh();
    }

    private function aDayLaterAWorkerRuns(): void
    {
        $this->travel(86601)->seconds();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
    }

    /** What retirement prevents: a stale reserved row does get its failed() run, a day later. */
    public function test_a_stale_reserved_row_is_failed_by_the_next_worker(): void
    {
        $this->reservedStagingJob('stgnone', withTask: false);

        $this->aDayLaterAWorkerRuns();

        $this->assertSame([CreateStaging::class], $this->failed);
    }

    public function test_a_retired_staging_copy_leaves_no_row_to_fail_and_the_new_copy_survives(): void
    {
        $task = $this->reservedStagingJob('stg267');
        $uuid = $task->job_id;

        $this->assertSame([(int) $task->id], TaskReconciler::reconcile(false, 120));

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertSame(0, DB::table('jobs')->count(), 'the reserved row went with the task');
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count(), 'and is on record as failed');

        // The user creates the staging copy again under the same name; a day passes.
        $this->makeUser('stg267', ['template' => 'dind']);
        $this->aDayLaterAWorkerRuns();

        $this->assertSame([], $this->failed, 'failed() never ran');
        $this->assertNotNull(User::findByUsername('stg267'));
    }

    /** A live worker keeps its row: it deletes the row itself when the job ends. */
    public function test_a_live_workers_row_is_left_alone(): void
    {
        $task = $this->reservedStagingJob('stglive');
        $pid = getmypid();
        $task->details = [Task::WORKER => ['pid' => $pid, 'start' => \App\Lib\Deploy\DeployLog\ProcessIdentity::startTime($pid)]];
        $task->save();

        $this->assertSame([], TaskReconciler::reconcile(false, 120));
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
