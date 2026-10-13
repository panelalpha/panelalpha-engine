<?php

namespace Tests\Unit\Task;

use App\Jobs\CreateStaging;
use App\Models\Task;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A staging copy marks its pending destination running before it is queued. A
 * task cancelled while queued runs no work, so the job itself must settle that
 * mark, or the destination stays pending and running for good.
 */
class CancelledBeforeWorkTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> what destroyDestination() saw, one entry per call */
    public array $destroyed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_staging_copy_cancelled_while_queued_removes_its_pending_destination(): void
    {
        $dest = $this->pendingStaging('pending');
        $task = $this->cancelledTask(CreateStaging::class, $dest->username);

        $this->stagingJob($dest)->attachTask($task)->handle();

        $dest->refresh();
        $this->assertSame('failed', $dest->asyncStatus()['staging']);
        $this->assertSame(CreateStaging::CANCELLED_BEFORE_START, $dest->getDetails()['error']);
        $this->assertSame([$dest->username], $this->destroyed);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    public function test_a_cancelled_staging_copy_never_destroys_a_destination_that_is_no_longer_pending(): void
    {
        $dest = $this->pendingStaging('active');
        $task = $this->cancelledTask(CreateStaging::class, $dest->username);

        $this->stagingJob($dest)->attachTask($task)->handle();

        $this->assertSame([], $this->destroyed);
        $this->assertSame('running', $dest->refresh()->asyncStatus()['staging']);
    }

    public function test_a_cancelled_staging_copy_whose_destination_was_deleted_does_nothing(): void
    {
        $dest = $this->pendingStaging('pending');
        $task = $this->cancelledTask(CreateStaging::class, $dest->username);
        $job = $this->stagingJob($dest);
        $dest->delete();

        $job->attachTask($task)->handle();

        $this->assertSame([], $this->destroyed);
    }
    private function pendingStaging(string $status): User
    {
        $live = $this->makeUser('cancelstg-live', ['template' => 'dind']);
        $dest = $this->makeUser('cancelstg-stg', [
            'template' => 'dind',
            'async_status' => ['staging' => 'running', 'source' => 'api'],
        ]);
        $dest->status = $status;
        $dest->staging = $live->id;
        $dest->save();

        return $dest->refresh();
    }

    private function cancelledTask(string $jobType, string $username): Task
    {
        $task = Task::start(jobType: $jobType, queue: 'default', username: $username);
        $this->assertTrue($task->cancelIfQueued());

        return $task->refresh();
    }

    /** A copy whose destroy is recorded: the real one reaches the host. */
    private function stagingJob(User $dest): CreateStaging
    {
        return new class ($dest->username, (int) $dest->id, $this) extends CreateStaging {
            public function __construct(string $destUsername, int $destId, private CancelledBeforeWorkTest $test)
            {
                parent::__construct($destUsername, $destId);
            }

            protected function destroyDestination(User $dest): void
            {
                $this->test->destroyed[] = $dest->username;
            }
        };
    }
}
