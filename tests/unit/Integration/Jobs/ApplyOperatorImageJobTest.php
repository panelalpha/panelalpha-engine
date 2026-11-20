<?php

namespace Tests\Integration\Jobs;

use App\Jobs\ApplyOperatorImage;
use App\Models\OperatorImage;
use App\Models\Task;
use App\Models\TaskLog;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The operator image job on a host that holds none of the images: what the
 * task log says, and what each failure leaves on the image and the task.
 */
class ApplyOperatorImageJobTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-operator-image-job-' . bin2hex(random_bytes(6));
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

    public function test_an_image_already_forgotten_completes_with_nothing_logged(): void
    {
        $task = $this->task();

        (new ApplyOperatorImage(404, ApplyOperatorImage::PUBLISH))->attachTask($task)->handle();

        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertSame([], $this->logs($task));
    }

    public function test_refreshing_a_local_image_gone_from_the_host_keeps_the_registry_copy(): void
    {
        $image = $this->image('mybase:1', OperatorImage::SOURCE_LOCAL, OperatorImage::STATUS_READY);
        $task = $this->task();

        (new ApplyOperatorImage($image->id, ApplyOperatorImage::REFRESH))->attachTask($task)->handle();

        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertContains('mybase:1 is not on the host any more; the cache registry keeps the copy it has.', $this->logs($task));
        $this->assertSame(OperatorImage::STATUS_READY, $image->refresh()->status);
    }

    public function test_publishing_a_local_image_the_host_lacks_fails_the_image_and_the_task(): void
    {
        $image = $this->image('mybase:2', OperatorImage::SOURCE_LOCAL, OperatorImage::STATUS_PENDING);
        $task = $this->task();

        $message = $this->failedRun(new ApplyOperatorImage($image->id, ApplyOperatorImage::PUBLISH), $task);

        $this->assertSame('mybase:2 is not on this host. Build or load it with docker first.', $message);
        $image->refresh();
        $this->assertSame(OperatorImage::STATUS_FAILED, $image->status);
        $this->assertSame($message, $image->error);
        $this->assertSame($message, $task->refresh()->details['error']);
    }

    public function test_removing_an_image_when_the_registry_cannot_be_listed_fails_the_image(): void
    {
        $image = $this->image('mybase:3', OperatorImage::SOURCE_LOCAL, OperatorImage::STATUS_READY);
        $task = $this->task();

        $message = $this->failedRun(new ApplyOperatorImage($image->id, ApplyOperatorImage::REMOVE), $task);

        $this->assertSame('Could not list the cache registry.', $message);
        $this->assertSame(OperatorImage::STATUS_FAILED, $image->refresh()->status);
        $this->assertNotNull(OperatorImage::query()->find($image->id));
    }

    public function test_a_failed_job_abandons_the_operation_and_fails_its_task(): void
    {
        $image = $this->image('mybase:4', OperatorImage::SOURCE_PULL, OperatorImage::STATUS_REMOVING);
        $task = $this->task();

        (new ApplyOperatorImage($image->id, ApplyOperatorImage::REMOVE))->attachTask($task)->failed(null);

        $image->refresh();
        $this->assertSame(OperatorImage::STATUS_FAILED, $image->status);
        $this->assertSame('Operator image job failed', $image->error);
        $this->assertSame('Operator image job failed', $task->refresh()->details['error']);
    }

    private function failedRun(ApplyOperatorImage $job, Task $task): string
    {
        try {
            $job->attachTask($task)->handle();
        } catch (\RuntimeException $e) {
            $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);

            return $e->getMessage();
        }

        $this->fail('The job did not throw.');
    }

    private function image(string $ref, string $source, string $status): OperatorImage
    {
        return OperatorImage::create(['ref' => $ref, 'source' => $source, 'status' => $status, 'why' => 'test base']);
    }

    private function task(): Task
    {
        return Task::start(jobType: ApplyOperatorImage::class, queue: 'default');
    }

    /** @return list<string> */
    private function logs(Task $task): array
    {
        return TaskLog::query()->where('task_id', $task->id)->orderBy('id')->pluck('log')->all();
    }
}
