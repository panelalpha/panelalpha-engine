<?php

namespace Tests\Integration\Jobs;

use App\Jobs\CopyProject;
use App\Lib\Project\ProjectCopier;
use App\Models\Task;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The copy a cancelled or failed create leaves behind is discarded, and the task says why. */
class CopyProjectJobTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<int> */
    public array $discarded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $test = $this;
        $this->app->instance(ProjectCopier::class, new class ($test) extends ProjectCopier {
            public function __construct(private CopyProjectJobTest $test)
            {
            }

            public function discard(int $copyId): void
            {
                $this->test->discarded[] = $copyId;
            }
        });
    }

    public function test_a_copy_cancelled_while_queued_discards_its_destination(): void
    {
        $task = Task::start(jobType: CopyProject::class, queue: 'default', username: 'copy-dst');
        $this->assertTrue($task->cancelIfQueued());

        $this->app->call([(new CopyProject(1, 2))->attachTask($task->refresh()), 'handle']);

        $this->assertSame([2], $this->discarded);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    public function test_a_failed_copy_fails_its_task_and_discards_its_destination(): void
    {
        $task = Task::start(jobType: CopyProject::class, queue: 'default', username: 'copy-dst');

        (new CopyProject(1, 3))->attachTask($task)->failed(null);

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Copy job failed', $task->details['error']);
        $this->assertSame([3], $this->discarded);
    }

    public function test_a_late_failure_keeps_a_settled_task_and_still_discards(): void
    {
        $task = Task::start(jobType: CopyProject::class, queue: 'default', username: 'copy-dst');
        $task->markCompleted();

        (new CopyProject(1, 4))->attachTask($task)->failed(new \RuntimeException('late'));

        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertSame([4], $this->discarded);
    }
}
