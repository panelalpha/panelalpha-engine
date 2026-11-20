<?php

namespace Tests\Integration\Jobs;

use App\Jobs\BuildRepositoryCache;
use App\Lib\Deploy\CacheManager\BuildCacheRefused;
use App\Models\Task;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class BuildRepositoryCacheJobTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_repository_that_can_have_no_shared_cache_fails_the_task_with_the_refusal(): void
    {
        $task = Task::start(jobType: BuildRepositoryCache::class, queue: 'default');
        $job = (new BuildRepositoryCache('git@github.com:acme/app.git', 'main'))->attachTask($task);

        try {
            $job->handle();
            $this->fail('The job did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Only a public https clone URL can have a shared build cache.', $e->getMessage());
            $this->assertInstanceOf(BuildCacheRefused::class, $e->getPrevious());
        }

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Only a public https clone URL can have a shared build cache.', $task->details['error']);
    }

    public function test_a_failed_job_fails_an_unfinished_task_and_leaves_a_finished_one(): void
    {
        $open = Task::start(jobType: BuildRepositoryCache::class, queue: 'default');
        $done = Task::start(jobType: BuildRepositoryCache::class, queue: 'default');
        $done->markCompleted();

        (new BuildRepositoryCache('https://github.com/acme/app.git', null))->attachTask($open)->failed(null);
        (new BuildRepositoryCache('https://github.com/acme/app.git', null))->attachTask($done)->failed(new \RuntimeException('late'));

        $this->assertSame('Build cache job failed', $open->refresh()->details['error']);
        $this->assertSame(Task::STATUS_COMPLETED, $done->refresh()->status);
    }
}
