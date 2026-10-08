<?php

namespace Tests\Unit\Task;

use App\Models\Task;
use App\Models\TaskLog;
use InvalidArgumentException;

class TaskModelTest extends SqliteTaskTestCase
{
    public function test_create_queued_requires_job_type_and_queue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Task::start(jobType: '', queue: 'default');
    }

    public function test_create_queued_rejects_empty_queue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: '');
    }

    public function test_create_queued_sets_initial_columns(): void
    {
        $task = Task::start(
            jobType: 'App\\Jobs\\RebuildJob',
            queue: 'default',
            username: 'acme',
            details: ['reason' => 'manual'],
        );

        $this->assertSame(Task::STATUS_QUEUED, $task->status);
        $this->assertSame('default', $task->queue);
        $this->assertSame('App\\Jobs\\RebuildJob', $task->job_type);
        $this->assertSame('acme', $task->username);
        $this->assertSame(['reason' => 'manual'], $task->details);
        $this->assertNull($task->job_id);
        $this->assertNull($task->pid);
        $this->assertNull($task->pid_start_time);
        $this->assertNotNull($task->queued_at);
        $this->assertNull($task->started_at);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->failed_at);
        $this->assertNull($task->cancelled_at);
    }

    public function test_create_queued_allows_null_username_and_details(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $this->assertNull($task->username);
        $this->assertNull($task->details);
    }

    public function test_queued_transitions_to_running_and_stamps_job_id(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $this->assertTrue($task->markRunning('horizon-uuid-1'));
        $task->refresh();

        $this->assertSame(Task::STATUS_RUNNING, $task->status);
        $this->assertSame('horizon-uuid-1', $task->job_id);
        $this->assertNotNull($task->started_at);
    }

    public function test_mark_running_on_cancelled_is_noop(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markCancelled();

        $this->assertFalse($task->markRunning('horizon-uuid-1'));
        $task->refresh();
        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertNull($task->job_id);
        $this->assertNull($task->started_at);
    }

    /** A worker and a delete each read the row as queued; the first write wins. */
    public function test_a_worker_never_runs_a_task_cancelled_after_it_read_the_row(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $workerCopy = Task::findOrFail($task->id);

        $this->assertTrue($task->cancelIfQueued());
        $this->assertFalse($workerCopy->markRunning('horizon-uuid-1'));

        $this->assertSame(Task::STATUS_CANCELLED, $workerCopy->status);
        $this->assertNull($workerCopy->started_at);
        $this->assertNull($workerCopy->job_id);
    }

    public function test_cancel_if_queued_loses_to_a_worker_that_took_the_task_first(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $deleteCopy = Task::findOrFail($task->id);

        $this->assertTrue($task->markRunning('horizon-uuid-1'));
        $this->assertFalse($deleteCopy->cancelIfQueued());

        $this->assertSame(Task::STATUS_RUNNING, $deleteCopy->status);
        $this->assertNull($deleteCopy->cancelled_at);
    }

    public function test_queued_can_complete_without_running(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $this->assertTrue($task->markCompleted());
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_terminal_transitions_are_noops(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markCompleted();

        $this->assertFalse($task->markRunning('x'));
        $this->assertFalse($task->markCompleted());
        $this->assertFalse($task->markFailed('boom'));
        $this->assertFalse($task->markCancelled());
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertNull($task->failed_at);
        $this->assertNull($task->cancelled_at);
    }

    public function test_queued_can_be_cancelled(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $this->assertTrue($task->markCancelled());
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertNotNull($task->cancelled_at);
    }

    public function test_mark_failed_sets_details_error_and_keeps_other_keys(): void
    {
        $task = Task::start(
            jobType: 'App\\Jobs\\RebuildJob',
            queue: 'default',
            details: ['reason' => 'manual'],
        );
        $task->markRunning('id-1');

        $this->assertTrue($task->markFailed('disk full'));
        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertNotNull($task->failed_at);
        $this->assertSame('manual', $task->details['reason']);
        $this->assertSame('disk full', $task->details['error']);
    }

    public function test_mark_failed_creates_details_object_when_null(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $task->markFailed('boom');
        $this->assertSame(['error' => 'boom'], $task->refresh()->details);
    }

    public function test_set_pid_null_clears_both_columns(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->setPid(4321, '12345');
        $this->assertSame(4321, $task->refresh()->pid);
        $this->assertSame('12345', $task->pid_start_time);

        $task->setPid(null, 'ignored');
        $task->refresh();
        $this->assertNull($task->pid);
        $this->assertNull($task->pid_start_time);
    }

    public function test_logs_after_returns_newer_rows_capped_and_next_cursor(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = TaskLog::create([
                'task_id' => $task->id,
                'log' => 'line-' . $i,
            ])->id;
        }

        $page = $task->logsAfter($ids[1], 2);
        $this->assertCount(2, $page);
        $this->assertSame('line-2', $page[0]->log);
        $this->assertSame('line-3', $page[1]->log);

        $empty = $task->logsAfter($ids[4], 2000);
        $this->assertCount(0, $empty);
    }

    public function test_logs_page_filters_by_since_unix_and_after_id(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $old = TaskLog::create(['task_id' => $task->id, 'log' => 'old']);
        $old->created_at = now()->subMinutes(5);
        $old->save();

        $mid = TaskLog::create(['task_id' => $task->id, 'log' => 'mid']);
        $mid->created_at = now()->subMinute();
        $mid->save();

        $new = TaskLog::create(['task_id' => $task->id, 'log' => 'new']);
        $new->created_at = now();
        $new->save();

        $since = $mid->created_at->timestamp;
        $page = $task->logsPage(0, $since);
        $this->assertCount(1, $page);
        $this->assertSame('new', $page[0]->log);

        $andCursor = $task->logsPage($mid->id, $old->created_at->timestamp);
        $this->assertCount(1, $andCursor);
        $this->assertSame('new', $andCursor[0]->log);
        $this->assertSame($new->id, $andCursor[0]->id);
    }

    public function test_logs_page_accepts_iso_datetime_since(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $old = TaskLog::create(['task_id' => $task->id, 'log' => 'old']);
        $old->created_at = now()->subHour();
        $old->save();

        TaskLog::create(['task_id' => $task->id, 'log' => 'fresh']);

        $page = $task->logsPage(0, $old->created_at->toIso8601String());
        $this->assertCount(1, $page);
        $this->assertSame('fresh', $page[0]->log);
    }

    public function test_parse_since_treats_zero_and_empty_as_absent(): void
    {
        $this->assertNull(Task::parseSince(null));
        $this->assertNull(Task::parseSince(0));
        $this->assertNull(Task::parseSince('0'));
        $this->assertNull(Task::parseSince(''));
    }

    public function test_logs_after_caps_at_2000(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $rows = [];
        for ($i = 0; $i < 2001; $i++) {
            $rows[] = [
                'task_id' => $task->id,
                'log' => 'x',
                'created_at' => now(),
            ];
        }
        TaskLog::insert($rows);

        $this->assertCount(2000, $task->logsAfter(0, 2000));
    }

    public function test_deleting_a_task_cascades_logs(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        TaskLog::create(['task_id' => $task->id, 'log' => 'line']);

        $task->delete();
        $this->assertSame(0, TaskLog::count());
    }
}
