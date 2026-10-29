<?php

namespace Tests\Unit\Task;

use App\Lib\Task\TaskLogPage;
use App\Models\Task;
use App\Models\TaskLog;

class TaskLogPageTest extends SqliteTaskTestCase
{
    public function test_a_page_holds_limit_rows_and_says_more_follow(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $ids = $this->lines($task, 5);

        $page = TaskLogPage::read($task, $ids[0], limit: 2);

        $this->assertSame(['line-1', 'line-2'], $page->rows->pluck('log')->all());
        $this->assertSame($ids[2], $page->nextAfterId);
        $this->assertTrue($page->more);
    }

    public function test_the_last_page_says_no_more_follow(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $ids = $this->lines($task, 4);

        $page = TaskLogPage::read($task, $ids[1], limit: 2);

        $this->assertSame(['line-2', 'line-3'], $page->rows->pluck('log')->all());
        $this->assertSame($ids[3], $page->nextAfterId);
        $this->assertFalse($page->more);
    }

    public function test_an_empty_page_keeps_the_cursor(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $ids = $this->lines($task, 2);

        $page = TaskLogPage::read($task, $ids[1]);

        $this->assertCount(0, $page->rows);
        $this->assertSame($ids[1], $page->nextAfterId);
        $this->assertFalse($page->more);
    }

    public function test_the_default_page_is_500_rows(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $this->lines($task, TaskLogPage::DEFAULT_LIMIT + 1);

        $page = TaskLogPage::read($task, 0);

        $this->assertSame(500, TaskLogPage::DEFAULT_LIMIT);
        $this->assertCount(TaskLogPage::DEFAULT_LIMIT, $page->rows);
        $this->assertTrue($page->more);
    }

    /**
     * @return list<int>
     */
    private function lines(Task $task, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['task_id' => $task->id, 'log' => 'line-' . $i, 'created_at' => now()];
        }
        TaskLog::insert($rows);

        return TaskLog::query()->where('task_id', $task->id)->orderBy('id')->pluck('id')->all();
    }
}
