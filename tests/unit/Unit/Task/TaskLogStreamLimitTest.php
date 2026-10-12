<?php

namespace Tests\Unit\Task;

use App\Http\Middleware\Authenticate;
use App\Lib\Task\LogStreamLimiter;
use App\Models\Task;

/**
 * A task log stream holds a php-fpm worker for the whole life of the task.
 * Core's pool has five children, so the number of concurrent streams is capped
 * to keep the API and the site-password gate answering.
 */
class TaskLogStreamLimitTest extends SqliteTaskTestCase
{
    /** @var list<resource> */
    private array $held = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
        @mkdir(LogStreamLimiter::directory(), 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $handle) {
            LogStreamLimiter::release($handle);
        }
        $this->held = [];

        foreach (glob(LogStreamLimiter::directory() . '/slot-*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_it_refuses_a_new_stream_once_the_slots_are_taken(): void
    {
        // Take every slot the limiter will hand out, as live streams would.
        for ($i = 0; $i < LogStreamLimiter::MAX_CONCURRENT; $i++) {
            $handle = LogStreamLimiter::acquire();
            $this->assertNotNull($handle, "slot {$i} should be free");
            $this->held[] = $handle;
        }

        $this->assertSame(LogStreamLimiter::MAX_CONCURRENT, LogStreamLimiter::active());

        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $response = $this->get('/api/tasks/' . $task->id . '/logs/stream');

        $response->assertStatus(503);
        $this->assertSame(LogStreamLimiter::MESSAGE, $response->json('message'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    /** The slot is counted while held and free again once released. */
    public function test_a_released_slot_is_reusable(): void
    {
        $first = LogStreamLimiter::acquire();
        $this->assertNotNull($first);
        $this->assertSame(1, LogStreamLimiter::active());

        LogStreamLimiter::release($first);
        $this->assertSame(0, LogStreamLimiter::active());

        $second = LogStreamLimiter::acquire();
        $this->assertNotNull($second);
        LogStreamLimiter::release($second);
    }

    /** With a free slot the stream still opens -- the cap is not a blanket refusal. */
    public function test_a_stream_opens_while_a_slot_is_free(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markCompleted();

        $response = $this->get('/api/tasks/' . $task->id . '/logs/stream');

        $response->assertStatus(200);
        $this->assertStringContainsString('"type":"finish"', $response->streamedContent());
        $this->assertSame(0, LogStreamLimiter::active(), 'the slot is released when the stream ends');
    }
}
