<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Exceptions\ProblemException;
use App\Jobs\BuildRepositoryCache;
use App\Lib\Deploy\CacheManager\BuildCaches;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class BuildCachesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Queue::fake();
    }

    private function caches(): BuildCaches
    {
        return new BuildCaches(new CacheRegistry(static fn (): never => throw new \LogicException('no registry in this test')));
    }

    public function test_a_build_is_queued_as_a_task_for_the_repository_and_branch(): void
    {
        $task = $this->caches()->queueBuild(' https://github.com/TwiN/gatus ', 'master');

        $this->assertSame(BuildRepositoryCache::class, $task->job_type);
        $this->assertSame(Task::STATUS_QUEUED, $task->status);
        $this->assertSame('github.com/twin/gatus', $task->details['repository_path']);
        $this->assertSame('master', $task->details['branch']);
        Queue::assertPushed(BuildRepositoryCache::class, fn (BuildRepositoryCache $job): bool => $job->repository === 'https://github.com/TwiN/gatus' && $job->branch === 'master');
    }

    public function test_the_same_build_twice_is_refused_while_the_first_is_queued(): void
    {
        $this->caches()->queueBuild('https://github.com/TwiN/gatus', null);

        try {
            $this->caches()->queueBuild('https://github.com/twin/gatus.git', '');
            $this->fail('expected a conflict');
        } catch (ProblemException $e) {
            $this->assertSame(ProblemException::STATUS_CONFLICT, $e->status);
            $this->assertStringContainsString(BuildCaches::BUSY, json_encode($e->problems ?? $e->errors()));
        }
        $this->assertInstanceOf(Task::class, $this->caches()->queueBuild('https://github.com/TwiN/gatus', 'develop'));
    }

    public function test_a_url_that_is_not_plainly_public_is_refused_before_anything_is_queued(): void
    {
        try {
            $this->caches()->queueBuild('https://oauth2:glpat-x@gitlab.com/acme/app.git', null);
            $this->fail('expected a refusal');
        } catch (ProblemException $e) {
            $this->assertSame(422, $e->status);
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_branch_owns_its_root_tag_and_its_builds_hashes_and_nothing_else(): void
    {
        $path = 'github.com/acme/app';

        $this->assertTrue(BuildCaches::belongs("buildcache/{$path}:main", $path, 'main'));
        $this->assertTrue(BuildCaches::belongs("buildcache/{$path}:main-fa1b2c3d4", $path, 'main'));
        $this->assertFalse(BuildCaches::belongs("buildcache/{$path}:main-feature", $path, 'main'));
        $this->assertFalse(BuildCaches::belongs("buildcache/{$path}-fork:main", $path, 'main'));
        $this->assertTrue(BuildCaches::belongs("buildcache/{$path}:main-feature", $path, null));
        $this->assertFalse(BuildCaches::belongs('panelalpha/php:8.3-pa1', $path, null));
    }
}
