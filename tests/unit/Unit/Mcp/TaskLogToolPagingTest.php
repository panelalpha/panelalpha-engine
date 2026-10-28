<?php

namespace Tests\Unit\Mcp;

use App\Http\Middleware\Authenticate;
use App\Lib\Task\TaskLogPage;
use App\Mcp\Tools\Api\ApiTool;
use App\Mcp\Tools\Api\Tasks\TaskGetTool;
use App\Mcp\Tools\Api\Tasks\TaskLogListTool;
use App\Models\Task;
use App\Models\TaskLog;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\Unit\Task\SqliteTaskTestCase;

/**
 * A long task log came back from task_get and task_log_list as one page of up
 * to 2000 rows, over the size a tool returns, and the error that replaced it
 * named no way to ask for less.
 */
class TaskLogToolPagingTest extends SqliteTaskTestCase
{
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
        $this->task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $this->deployLines(TaskLogPage::MAX_LIMIT);
    }

    public function test_the_default_page_of_a_long_log_comes_back_well_under_the_limit(): void
    {
        $response = (new TaskLogListTool())->handle(new Request(['id' => $this->task->id]));

        $this->assertFalse($response->isError(), (string) $response->content());
        $this->assertLessThan(ApiTool::MAX_RESULT_BYTES / 2, strlen((string) $response->content()));
        $body = $this->body($response);
        $this->assertCount(TaskLogPage::DEFAULT_LIMIT, $body['data']);
        $this->assertTrue($body['meta']['more']);
        $this->assertSame($body['data'][TaskLogPage::DEFAULT_LIMIT - 1]['id'], $body['meta']['next_after_id']);
    }

    public function test_task_get_pages_on_from_next_after_id_to_the_end(): void
    {
        $first = $this->taskGet([]);
        $second = $this->taskGet(['after_id' => $first['next_after_id']]);
        $last = $this->taskGet(['after_id' => (int) TaskLog::query()->max('id') - 1]);

        $this->assertTrue($first['more']);
        $this->assertSame($first['next_after_id'] + 1, $second['logs'][0]['id']);
        $this->assertCount(TaskLogPage::DEFAULT_LIMIT, $second['logs']);
        $this->assertCount(1, $last['logs']);
        $this->assertFalse($last['more']);
    }

    public function test_a_page_over_the_limit_says_which_limit_fits_and_which_cursor_to_repeat(): void
    {
        $afterId = (int) TaskLog::query()->min('id') - 1;

        $response = (new TaskGetTool())->handle(new Request([
            'id' => $this->task->id,
            'after_id' => $afterId,
            'limit' => TaskLogPage::MAX_LIMIT,
        ]));

        $this->assertTrue($response->isError());
        $message = (string) $response->content();
        $this->assertMatchesRegularExpression('/Call again with `limit` (\d+) or less \(this call asked for 2000\)/', $message);
        $this->assertStringContainsString("and `after_id` {$afterId} again", $message);
        preg_match('/`limit` (\d+) or less/', $message, $suggested);
        $retry = (new TaskGetTool())->handle(new Request(['id' => $this->task->id, 'after_id' => $afterId, 'limit' => (int) $suggested[1]]));
        $this->assertFalse($retry->isError(), (string) $retry->content());
    }

    private function deployLines(int $count): void
    {
        $message = '#12 41.07 npm warn deprecated inflight@1.0.6: This module is not supported, and leaks memory.';
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'task_id' => $this->task->id,
                'log' => json_encode(['ts' => 1761645600 + $i, 'stage' => 'running', 'level' => 'dim', 'msg' => $message], JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            TaskLog::insert($chunk);
        }
    }

    /**
     * @param  array<string, int>  $arguments
     * @return array<string, mixed>
     */
    private function taskGet(array $arguments): array
    {
        $response = (new TaskGetTool())->handle(new Request(['id' => $this->task->id] + $arguments));
        $this->assertFalse($response->isError(), (string) $response->content());

        return $this->body($response)['data'];
    }

    /**
     * The API's answer inside the tool's {status, data} result.
     *
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        return json_decode((string) $response->content(), true)['data'];
    }
}
