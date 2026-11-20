<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Exceptions\ProblemException;
use App\Jobs\ApplyOperatorImage;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\OperatorImageHost;
use App\Lib\Deploy\CacheManager\OperatorImageRef;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Lib\Deploy\CacheManager\PublicImageLookup;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\OperatorImage;
use App\Models\Task;
use App\System;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Operator images with the host work faked: what is recorded, what is refused,
 * and the state a failure leaves the image in.
 */
class OperatorImagesTest extends TestCase
{
    use InMemoryDatabase;

    private const DOCKERFILE = "FROM debian:bookworm-slim\nRUN apt-get update\n";

    private FakeOperatorImageHost $host;

    private ?int $hubStatus = 404;

    /** @var list<string> */
    private array $steps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Queue::fake();
        $this->host = new FakeOperatorImageHost;
    }

    public function test_a_build_image_is_recorded_pending_with_its_dockerfile(): void
    {
        $image = $this->images()->create([
            'ref' => 'whmcs-dev-base:php8.3',
            'source' => 'build',
            'dockerfile' => self::DOCKERFILE,
            'build_args' => ['PHP' => '8.3'],
            'why' => 'WHMCS dev base',
        ]);

        $this->assertSame(OperatorImage::STATUS_PENDING, $image->status);
        $this->assertSame(self::DOCKERFILE, $image->fresh()->dockerfile);
        $this->assertSame(['PHP' => '8.3'], $image->fresh()->build_args);
    }

    public function test_a_name_docker_hub_publishes_is_refused_for_a_build(): void
    {
        $this->hubStatus = 200;

        $this->assertProblem(OperatorImages::REF_PUBLIC, fn () => $this->images()->create([
            'ref' => 'mysql:8', 'source' => 'build', 'dockerfile' => self::DOCKERFILE,
        ]));
        $this->assertSame(0, OperatorImage::query()->count());
    }

    public function test_a_name_that_cannot_be_checked_is_refused(): void
    {
        $this->hubStatus = null;
        $this->host->onHost['mybase:1'] = true;

        $this->assertProblem(OperatorImages::REF_UNVERIFIED, fn () => $this->images()->create([
            'ref' => 'mybase:1', 'source' => 'local',
        ]));
    }

    public function test_a_pull_image_is_never_asked_of_docker_hub(): void
    {
        $this->hubStatus = 200;

        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        $this->assertSame('pull', $image->source);
    }

    public function test_a_local_image_the_host_lacks_is_refused_before_docker_hub_is_asked(): void
    {
        $this->hubStatus = null;
        $this->assertProblem(OperatorImages::NOT_ON_HOST, fn () => $this->images()->create([
            'ref' => 'mybase:1', 'source' => 'local',
        ]));

        $this->host->onHost['mybase:1'] = true;
        $this->hubStatus = 404;
        $this->assertSame('local', $this->images()->create(['ref' => 'mybase:1', 'source' => 'local'])->source);
    }

    public function test_the_same_ref_twice_is_a_conflict(): void
    {
        $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        try {
            $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);
            $this->fail('a second add of the same ref was accepted');
        } catch (ProblemException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString(OperatorImages::REF_TAKEN, json_encode($e->problems ?? $e->errors()) ?: '');
        }
    }

    public function test_bad_input_is_refused_with_its_own_code(): void
    {
        $this->assertProblem(OperatorImages::SOURCE_INVALID, fn () => $this->images()->create(['ref' => 'a:1', 'source' => 'copy']));
        $this->assertProblem(OperatorImageRef::INVALID, fn () => $this->images()->create(['ref' => 'nobase', 'source' => 'pull']));
        $this->assertProblem(OperatorImages::DOCKERFILE_REQUIRED, fn () => $this->images()->create(['ref' => 'a:1', 'source' => 'build']));
        $this->assertProblem(OperatorImages::DOCKERFILE_INVALID, fn () => $this->images()->create(['ref' => 'a:1', 'source' => 'build', 'dockerfile' => 'RUN true']));
        $this->assertProblem(OperatorImages::BUILD_ONLY, fn () => $this->images()->create(['ref' => 'a:1', 'source' => 'pull', 'dockerfile' => self::DOCKERFILE]));
        $this->assertProblem(OperatorImages::BUILD_ARGS_INVALID, fn () => $this->images()->create(['ref' => 'a:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE, 'build_args' => ['1BAD' => 'x']]));
        $this->assertProblem(OperatorImages::BUILD_ARGS_INVALID, fn () => $this->images()->create(['ref' => 'a:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE, 'build_args' => ['x', 'y']]));
    }

    public function test_publishing_records_what_the_registry_holds(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        $this->images()->publish($image, false, static function (string $chunk): void {});

        $image->refresh();
        $this->assertSame(OperatorImage::STATUS_READY, $image->status);
        $this->assertSame('sha256:abc', $image->digest);
        $this->assertSame(1234, $image->size_bytes);
        $this->assertNotNull($image->refreshed_at);
        $this->assertSame(['acquire debian:bookworm-slim', 'publish debian:bookworm-slim'], $this->host->calls);
    }

    public function test_a_failed_publish_keeps_the_image_as_failed_with_the_reason(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);
        $this->host->failAcquire = 'pull access denied';

        try {
            $this->images()->publish($image, false, static function (string $chunk): void {});
            $this->fail('a failed pull was reported as published');
        } catch (RuntimeException) {
        }

        $image->refresh();
        $this->assertSame(OperatorImage::STATUS_FAILED, $image->status);
        $this->assertSame('pull access denied', $image->error);
    }

    public function test_a_local_image_gone_from_the_host_is_kept_on_refresh(): void
    {
        $this->host->onHost['mybase:1'] = true;
        $image = $this->images()->create(['ref' => 'mybase:1', 'source' => 'local']);
        unset($this->host->onHost['mybase:1']);

        $this->assertFalse($this->images()->refresh($image, static function (string $chunk): void {}));
        $this->assertSame([], $this->host->calls);
    }

    public function test_a_refresh_rebuilds_from_upstream(): void
    {
        $image = $this->images()->create(['ref' => 'a:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE]);

        $this->assertTrue($this->images()->refresh($image, static function (string $chunk): void {}));
        $this->assertSame(['acquire a:1 fresh', 'publish a:1'], $this->host->calls);
    }

    public function test_only_a_changed_build_asks_for_a_rebuild(): void
    {
        $image = $this->images()->create(['ref' => 'a:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE]);

        $this->assertFalse($this->images()->update($image, ['why' => 'note']));
        $this->assertFalse($this->images()->update($image, ['dockerfile' => self::DOCKERFILE]));
        $this->assertTrue($this->images()->update($image, ['build_args' => ['V' => '2']]));
        $this->assertSame('note', $image->fresh()->why);
        $this->assertSame(['V' => '2'], $image->fresh()->build_args);
    }

    public function test_a_pull_image_has_no_build_to_change(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        $this->assertProblem(OperatorImages::BUILD_ONLY, fn () => $this->images()->update($image, ['dockerfile' => self::DOCKERFILE]));
    }

    public function test_removing_unpublishes_drops_host_copies_and_forgets_it(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        $this->assertSame('removed from the cache registry', $this->images()->remove($image));
        $this->assertNull(OperatorImage::findByRef('debian:bookworm-slim'));
        $this->assertSame(['unpublish debian:bookworm-slim', 'drop debian:bookworm-slim'], $this->host->calls);
    }

    public function test_a_failed_removal_keeps_the_image_and_its_protection(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);
        $this->host->failUnpublish = true;

        try {
            $this->images()->remove($image);
            $this->fail('a failed removal was reported as done');
        } catch (RuntimeException) {
        }

        $this->assertSame(OperatorImage::STATUS_FAILED, OperatorImage::findByRef('debian:bookworm-slim')?->status);
        $this->assertSame(['debian:bookworm-slim'], OperatorImage::refs());
    }

    public function test_a_queued_operation_is_a_task_and_blocks_the_next_until_it_ends(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        $task = $this->images()->queue($image, ApplyOperatorImage::PUBLISH);

        Queue::assertPushed(ApplyOperatorImage::class, fn (ApplyOperatorImage $job): bool => $job->imageId === $image->id && $job->taskId === $task->id);
        $this->assertSame($task->id, $image->fresh()->task_id);
        $this->assertProblem(OperatorImages::BUSY, fn () => $this->images()->queue($image->fresh(), ApplyOperatorImage::REMOVE));

        $task->fill(['status' => Task::STATUS_COMPLETED])->save();
        $this->images()->queue($image->fresh(), ApplyOperatorImage::REMOVE);
        $this->assertSame(OperatorImage::STATUS_REMOVING, $image->fresh()->status);
    }

    public function test_a_publish_job_that_dies_leaves_the_image_failed_with_the_reason(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);
        $task = $this->images()->queue($image, ApplyOperatorImage::PUBLISH);

        (new ApplyOperatorImage($image->id, ApplyOperatorImage::PUBLISH))->attachTask($task)
            ->failed(new RuntimeException('The job has timed out.'));

        $image->refresh();
        $this->assertSame(OperatorImage::STATUS_FAILED, $image->status);
        $this->assertSame('The job has timed out.', $image->error);
        $this->assertSame(Task::STATUS_FAILED, $task->fresh()->status);
    }

    public function test_a_remove_job_that_dies_leaves_the_image_failed_and_kept(): void
    {
        $image = $this->images()->create(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);
        $task = $this->images()->queue($image, ApplyOperatorImage::REMOVE);

        (new ApplyOperatorImage($image->id, ApplyOperatorImage::REMOVE))->attachTask($task)->failed(null);

        $image->refresh();
        $this->assertSame(OperatorImage::STATUS_FAILED, $image->status);
        $this->assertSame('Operator image job failed', $image->error);
        $this->assertSame(['debian:bookworm-slim'], OperatorImage::refs());
    }

    public function test_a_dead_job_leaves_an_image_it_was_not_changing_alone(): void
    {
        $image = OperatorImage::query()->create(['ref' => 'a:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);

        (new ApplyOperatorImage($image->id, ApplyOperatorImage::REMOVE))->failed(new RuntimeException('killed'));

        $this->assertSame(OperatorImage::STATUS_READY, $image->fresh()->status);
        $this->assertNull($image->fresh()->error);
    }

    public function test_an_api_add_records_the_image_and_queues_its_publish(): void
    {
        $image = $this->images()->createQueued(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);

        $task = Task::query()->findOrFail($image->task_id);
        Queue::assertPushed(ApplyOperatorImage::class, fn (ApplyOperatorImage $job): bool => $job->imageId === $image->id
            && $job->action === ApplyOperatorImage::PUBLISH && $job->taskId === $task->id);
        $this->assertSame(OperatorImage::STATUS_PENDING, $image->fresh()->status);
    }

    public function test_an_api_change_queues_a_rebuild_only_when_the_build_changed(): void
    {
        $image = $this->images()->create(['ref' => 'a:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE]);

        $this->assertFalse($this->images()->updateQueued($image, ['why' => 'note']));
        Queue::assertNothingPushed();
        $this->assertNull($image->task_id);

        $this->assertTrue($this->images()->updateQueued($image, ['build_args' => ['V' => '2']]));
        Queue::assertPushed(ApplyOperatorImage::class, fn (ApplyOperatorImage $job): bool => $job->taskId === $image->task_id
            && $job->action === ApplyOperatorImage::PUBLISH);
    }

    public function test_the_weekly_refresh_skips_images_being_changed_and_reports_each_step(): void
    {
        $running = Task::start(jobType: ApplyOperatorImage::class, queue: 'default');
        OperatorImage::query()->create(['ref' => 'a-busy:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_PENDING, 'task_id' => $running->id]);
        OperatorImage::query()->create(['ref' => 'b-removing:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_REMOVING]);
        OperatorImage::query()->create(['ref' => 'c-gone:1', 'source' => 'local', 'status' => OperatorImage::STATUS_READY]);
        OperatorImage::query()->create(['ref' => 'd-pull:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);

        $this->assertTrue($this->images()->refreshAll($this->recordSteps()));

        $this->assertSame([
            'a-busy:1 skipped',
            'b-removing:1 skipped',
            'c-gone:1 started',
            'c-gone:1 kept',
            'd-pull:1 started',
            'd-pull:1 renewed',
        ], $this->steps);
        $this->assertSame(['acquire d-pull:1 fresh', 'publish d-pull:1'], $this->host->calls);
    }

    public function test_a_failed_weekly_refresh_is_reported_and_the_next_image_still_runs(): void
    {
        OperatorImage::query()->create(['ref' => 'a:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);
        OperatorImage::query()->create(['ref' => 'b:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);
        $this->host->failAcquire = 'pull access denied';

        $this->assertTrue($this->images()->refreshAll($this->recordSteps()));

        $this->assertSame(['a:1 started', 'a:1 failed pull access denied', 'b:1 started', 'b:1 failed pull access denied'], $this->steps);
        $this->assertSame(OperatorImage::STATUS_FAILED, OperatorImage::findByRef('a:1')?->status);
    }

    public function test_the_weekly_refresh_renews_nothing_when_the_images_cannot_be_read(): void
    {
        Schema::drop('operator_images');

        $this->assertFalse($this->images()->refreshAll($this->recordSteps()));
        $this->assertSame([], $this->steps);
    }

    /**
     * @return callable(OperatorImage, string, ?string): void
     */
    private function recordSteps(): callable
    {
        return function (OperatorImage $image, string $step, ?string $error): void {
            $this->steps[] = trim("{$image->ref} {$step} {$error}");
        };
    }

    private function images(): OperatorImages
    {
        return new OperatorImages($this->host, new PublicImageLookup(fn (): ?int => $this->hubStatus));
    }

    private function assertProblem(string $code, callable $call): void
    {
        try {
            $call();
        } catch (ProblemException $e) {
            $this->assertStringContainsString($code, json_encode($e->problems ?? []) ?: '', "expected {$code}");

            return;
        }
        $this->fail("expected the problem {$code}");
    }
}

class FakeOperatorImageHost extends OperatorImageHost
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, true> */
    public array $onHost = [];

    public ?string $failAcquire = null;

    public bool $failUnpublish = false;

    public function __construct()
    {
        parent::__construct(new System, new DindImageStore, new CacheRegistry(static fn (): ?array => null), new RegistryImageConfig(static fn (): ?string => null));
    }

    public function acquire(OperatorImage $image, bool $fresh, callable $output): void
    {
        if ($this->failAcquire !== null) {
            throw new RuntimeException($this->failAcquire);
        }
        $this->calls[] = 'acquire '.$image->ref.($fresh ? ' fresh' : '');
    }

    public function isOnHost(string $ref): bool
    {
        return isset($this->onHost[$ref]);
    }

    public function publish(string $ref, callable $output): void
    {
        $this->calls[] = "publish {$ref}";
    }

    public function measure(string $ref): array
    {
        return ['digest' => 'sha256:abc', 'size' => 1234];
    }

    public function unpublish(string $ref): string
    {
        if ($this->failUnpublish) {
            throw new RuntimeException('Could not lock');
        }
        $this->calls[] = "unpublish {$ref}";

        return 'removed from the cache registry';
    }

    public function dropHostCopies(OperatorImage $image): void
    {
        $this->calls[] = "drop {$image->ref}";
    }
}
