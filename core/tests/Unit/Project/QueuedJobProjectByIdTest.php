<?php

namespace Tests\Unit\Project;

use App\Exceptions\NotFoundException;
use App\Jobs\CreateStaging;
use App\Jobs\DeployProject;
use App\Models\Task;
use App\Models\User;
use App\System\Project\Deployment\RollBackProject;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A queued first deploy or staging copy finds its project by row id, so a
 * project deleted and created again under the same name is never the one it
 * deploys into, copies into, marks or destroys.
 */
class QueuedJobProjectByIdTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    private function task(string $jobType, string $username): Task
    {
        return Task::start(jobType: $jobType, queue: 'default', username: $username);
    }

    /** The old project's id, with a newer project holding its name. */
    private function nameTakenOver(string $username): array
    {
        $old = $this->makeUser($username);
        $oldId = (int) $old->id;
        $old->delete();

        return [$oldId, $this->makeUser($username)];
    }

    public function test_a_deploy_never_deploys_a_newer_project_that_took_the_name(): void
    {
        [$oldId, $newer] = $this->nameTakenOver('alice');
        $task = $this->task(DeployProject::class, 'alice');

        try {
            // The real pipeline runs past the lookup, so getting this far without it is the proof.
            (new DeployProject('alice', userId: $oldId))->attachTask($task)->handle();
            $this->fail('The deploy found a project.');
        } catch (NotFoundException $e) {
            $this->assertSame("Project 'alice' not found.", $e->getMessage());
        }

        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
        $this->assertTrue(User::query()->whereKey($newer->id)->exists());
    }

    public function test_a_deploy_finds_its_project_by_id_and_a_payload_without_one_by_name(): void
    {
        $user = $this->makeUser('alice');
        $lookup = new ReflectionMethod(DeployProject::class, 'project');

        $this->assertSame($user->id, $lookup->invoke(new DeployProject('alice', userId: (int) $user->id))->id);
        $this->assertSame($user->id, $lookup->invoke(new DeployProject('alice'))->id);
        // A job queued before the id existed unserializes with it unset.
        $this->assertTrue((new ReflectionProperty(DeployProject::class, 'userId'))->hasDefaultValue());
    }

    public function test_a_staging_copy_never_copies_into_a_newer_project_that_took_the_name(): void
    {
        [$oldId, $newer] = $this->nameTakenOver('alicestg');
        $task = $this->task(CreateStaging::class, 'alicestg');

        try {
            (new CreateStaging('alicestg', $oldId))->attachTask($task)->handle();
            $this->fail('The copy found a destination.');
        } catch (NotFoundException $e) {
            $this->assertSame("Project 'alicestg' not found.", $e->getMessage());
        }

        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
        $this->assertTrue(User::query()->whereKey($newer->id)->exists());
    }

    public function test_a_failed_staging_copy_leaves_a_newer_project_that_took_the_name_alone(): void
    {
        [$oldId, $newer] = $this->nameTakenOver('alicestg');
        $task = $this->task(CreateStaging::class, 'alicestg');

        // destroy() would reach the host; it is not reached at all.
        (new CreateStaging('alicestg', $oldId))->attachTask($task)->failed(new \RuntimeException('rsync failed'));

        $newer->refresh();
        $this->assertTrue($newer->exists);
        $this->assertArrayNotHasKey('staging', $newer->asyncStatus());
        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }

    public function test_a_staging_copy_without_an_id_still_finds_its_destination_by_name(): void
    {
        $dest = $this->makeUser('alicestg');
        $lookup = new ReflectionMethod(CreateStaging::class, 'destination');

        $this->assertSame($dest->id, $lookup->invoke(new CreateStaging('alicestg'))?->id);
        $this->assertSame($dest->id, $lookup->invoke(new CreateStaging('alicestg', (int) $dest->id))?->id);
        $this->assertTrue((new ReflectionProperty(CreateStaging::class, 'destId'))->hasDefaultValue());
    }

    public function test_a_rollback_never_destroys_a_newer_project_that_took_the_name(): void
    {
        $old = $this->makeUser('alice');
        $old->delete();
        $newer = $this->makeUser('alice');

        // destroy() of the newer project would reach the host and throw here.
        (new RollBackProject())->afterFailure($old, 'deploy failed');

        $this->assertTrue(User::query()->whereKey($newer->id)->exists());
    }
}
