<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\ApplyOperatorImage;
use App\Jobs\BuildRepositoryCache;
use App\Models\OperatorImage;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/**
 * /system/images and /system/build-caches: an operator image is listed beside the engine's own, a change to it is
 * queued as a task, and the build caches are read from the cache registry through the host.
 */
class SystemImageHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const CATALOG = '/v2/_catalog';

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        $this->broker = new ScriptedBroker();
    }

    protected function tearDown(): void
    {
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_an_operator_image_is_listed_with_the_engines_own(): void
    {
        $image = $this->image();

        $listed = $this->getJson('/api/system/images')->assertOk()->json('data');

        $this->assertSame(['operator', $image->ref], [$listed[0]['owner'], $listed[0]['ref']]);
        $this->assertContains('engine', array_column($listed, 'owner'));
        $this->getJson("/api/system/images/{$image->id}")->assertOk()->assertJsonPath('data.ref', $image->ref);
    }

    public function test_a_refresh_is_queued_and_a_second_change_waits_for_it(): void
    {
        $image = $this->image();

        $taskId = $this->postJson("/api/system/images/{$image->id}/refresh")
            ->assertStatus(202)
            ->assertJsonPath('data.status', OperatorImage::STATUS_PENDING)
            ->json('task_id');

        $this->assertSame('operator_image_' . ApplyOperatorImage::REFRESH, Task::query()->find($taskId)?->details['action']);
        Queue::assertPushed(ApplyOperatorImage::class);
        $this->deleteJson("/api/system/images/{$image->id}")->assertStatus(409)->assertJsonPath('problems.0.code', 'operator_image_busy');
    }

    public function test_a_removal_is_queued(): void
    {
        $image = $this->image();

        $this->deleteJson("/api/system/images/{$image->id}")
            ->assertStatus(202)
            ->assertJsonPath('data.status', OperatorImage::STATUS_REMOVING);
    }

    public function test_an_update_that_changes_nothing_queues_nothing(): void
    {
        $image = $this->image();

        $this->putJson("/api/system/images/{$image->id}", ['why' => 'kept for the WHMCS team'])
            ->assertOk()
            ->assertJsonPath('data.why', 'kept for the WHMCS team')
            ->assertJsonMissingPath('task_id');
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_image_is_not_found(): void
    {
        foreach ([['GET', ''], ['PUT', ''], ['POST', '/refresh'], ['DELETE', '']] as [$method, $suffix]) {
            $this->json($method, "/api/system/images/999{$suffix}", ['why' => 'x'])->assertNotFound();
        }
    }

    public function test_build_caches_are_read_from_the_registry(): void
    {
        $this->registryAnswers('{"repositories":[]}');

        $this->getJson('/api/system/build-caches')->assertOk()->assertExactJson(['data' => []]);
        $this->deleteJson('/api/system/build-caches', ['repository' => 'https://github.com/acme/shop'])->assertOk()->assertJsonPath('removed', []);
        $this->assertStringContainsString(self::CATALOG, implode(' ', $this->broker->valuesOf('host.httpRequest')[0]));
    }

    public function test_an_unreachable_registry_is_unavailable(): void
    {
        $this->registryAnswers('', 503);

        $this->getJson('/api/system/build-caches')->assertStatus(503)->assertExactJson(['message' => 'Could not list the cache registry.']);
        $this->deleteJson('/api/system/build-caches', ['repository' => 'https://github.com/acme/shop'])->assertStatus(503);
    }

    public function test_a_build_cache_is_queued_for_a_repository_and_branch(): void
    {
        $taskId = $this->postJson('/api/system/build-caches', ['repository' => ' https://github.com/acme/shop ', 'branch' => ' '])
            ->assertStatus(202)
            ->json('task_id');

        $this->assertNotNull(Task::query()->find($taskId));
        Queue::assertPushed(BuildRepositoryCache::class);
        $this->postJson('/api/system/build-caches', ['branch' => 'main'])->assertUnprocessable()->assertJsonValidationErrors('repository');
    }

    private function image(): OperatorImage
    {
        return OperatorImage::create(['ref' => 'whmcs-dev-base:php8.3', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);
    }

    private function registryAnswers(string $body, int $status = 200): void
    {
        $this->broker->answer('host.httpRequest', "HTTP/1.1 {$status} OK\r\nContent-Type: application/json\r\n\r\n{$body}", match: self::CATALOG);
    }
}
