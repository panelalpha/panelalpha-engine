<?php

namespace Tests\Integration\Jobs;

use App\Exceptions\NotFoundException;
use App\Jobs\CreateStaging;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A staging copy that cannot start, and what its failure handler leaves on
 * the destination, the source and the task. Every root action is refused.
 */
class CreateStagingJobTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-staging-job-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $client = $this->dir . '/pa-root';
        file_put_contents($client, "#!/bin/sh\necho 'Operation not permitted' >&2\nexit 1\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_destination_that_no_longer_exists_fails_the_task(): void
    {
        $task = $this->task('stg-gone');

        $e = $this->failedRun(new CreateStaging('stg-gone', 404), $task);

        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertSame("Project 'stg-gone' not found.", $e->getMessage());
        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }

    public function test_a_destination_without_a_live_parent_fails_the_task(): void
    {
        $dest = $this->makeUser('stg-orphan', ['template' => 'dind']);
        $task = $this->task($dest->username);

        $e = $this->failedRun(new CreateStaging($dest->username, (int) $dest->id), $task);

        $this->assertSame("Staging 'stg-orphan' has no live parent.", $e->getMessage());
        $this->assertSame("Staging 'stg-orphan' has no live parent.", $task->refresh()->details['error']);
    }

    public function test_a_copy_the_projects_refuse_fails_the_task_and_the_destination(): void
    {
        $live = $this->makeUser('stg-live', ['template' => 'dind']);
        $dest = $this->makeUser('stg-copy', ['template' => 'classic']);
        $dest->staging = $live->id;
        $dest->save();
        $task = $this->task($dest->username);

        $e = $this->failedRun(new CreateStaging($dest->username, (int) $dest->id, false), $task);

        $expected = "Projects 'stg-live' (dind) and 'stg-copy' (classic) have different templates.";
        $this->assertSame($expected, $e->getMessage());
        $this->assertSame($expected, $task->refresh()->details['error']);
        $dest->refresh();
        $this->assertSame('failed', $dest->asyncStatus()['staging']);
        $this->assertSame($expected, $dest->getDetails()['error']);
    }

    public function test_a_failure_after_the_destination_was_deleted_only_fails_the_task(): void
    {
        $task = $this->task('stg-deleted');

        (new CreateStaging('stg-deleted'))->attachTask($task)->failed(new \RuntimeException('copy died'));

        $this->assertSame('copy died', $task->refresh()->details['error']);
        $this->assertNull(User::findByUsername('stg-deleted'));
    }

    public function test_a_failure_of_a_destination_without_a_live_parent_still_marks_it(): void
    {
        $dest = $this->makeUser('stg-alone', ['template' => 'dind']);
        $dest->status = 'pending';
        $dest->save();
        $child = $this->makeUser('stg-alone-child', ['template' => 'dind']);
        $child->staging = $dest->id;
        $child->save();
        $task = $this->task($dest->username);

        (new CreateStaging($dest->username, (int) $dest->id))->attachTask($task)->failed(new \RuntimeException('copy died'));

        $dest->refresh();
        $this->assertSame('failed', $dest->asyncStatus()['staging']);
        $this->assertSame('copy died', $dest->getDetails()['error']);
    }

    public function test_a_failure_marks_the_destination_and_logs_a_destroy_it_refuses(): void
    {
        $live = $this->makeUser('stg-src', ['template' => 'dind']);
        $dest = $this->makeUser('stg-dst', ['template' => 'dind']);
        $dest->staging = $live->id;
        $dest->save();
        $child = $this->makeUser('stg-dst-child', ['template' => 'dind']);
        $child->staging = $dest->id;
        $child->save();
        $task = $this->task($dest->username);
        Log::spy();

        (new CreateStaging($dest->username, (int) $dest->id))->attachTask($task)->failed(new \RuntimeException('copy died'));

        $dest->refresh();
        $this->assertSame('failed', $dest->asyncStatus()['staging']);
        $this->assertSame('copy died', $dest->getDetails()['error']);
        $this->assertSame('copy died', $task->refresh()->details['error']);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'CreateStaging cleanup failed'
                && $context['dest'] === 'stg-dst'
                && str_contains($context['exception']->getMessage(), "while staging 'stg-dst-child' exists"),
        )->once();
    }

    private function failedRun(CreateStaging $job, Task $task): \Throwable
    {
        try {
            $job->attachTask($task)->handle();
        } catch (\Throwable $e) {
            $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);

            return $e;
        }

        $this->fail('The job did not throw.');
    }

    private function task(string $username): Task
    {
        return Task::start(jobType: CreateStaging::class, queue: 'default', username: $username);
    }
}
