<?php

namespace Tests\Unit\WordpressMigration;

use App\Http\Middleware\EnsureTokenMayUseApi;
use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestNetwork;

/**
 * Start, status, and resume are registered. A start whose JSON has no
 * password does not create a migration record.
 */
class WordpressMigrationHttpTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            'auth:api',
            EnsureTokenMayUseApi::class,
        ]);

        $this->createTargetTables();

        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => $host === 'example.com' ? [TestNetwork::routable(34)] : [$host],
        );
    }

    protected function tearDown(): void
    {
        $this->dropTargetTables();
        parent::tearDown();
    }

    public function test_start_status_and_resume_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => implode('|', $route->methods()) . ' ' . $route->uri())
            ->all();

        $this->assertTrue($this->routeExists($uris, 'POST', 'api/wordpress/migrations'));
        $this->assertTrue($this->routeExists($uris, 'GET', 'api/wordpress/migrations/{id}'));
        $this->assertTrue($this->routeExists($uris, 'POST', 'api/wordpress/migrations/{id}/resume'));
    }

    public function test_start_without_password_does_not_create_a_record(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/wordpress/migrations', [
            'url' => 'https://example.com/blog',
            'username' => 'admin',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_start_with_a_blocked_source_url_does_not_store_the_password(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/wordpress/migrations', [
            'url' => 'http://' . TestNetwork::LOOPBACK . '/latest/meta-data',
            'username' => 'admin',
            'password' => 'secret-must-not-persist',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WordpressMigration::query()->count());
        $this->assertStringNotContainsString('secret-must-not-persist', (string) $response->getContent());
    }

    public function test_start_queues_the_migration_job(): void
    {
        $this->withoutMiddleware();
        Queue::fake();

        $response = $this->postJson('/api/wordpress/migrations', [
            'url' => 'https://example.com/blog',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertStatus(202);
        $id = $response->json('id');
        $this->assertIsInt($id);

        Queue::assertPushed(MigrateWordpress::class, function (MigrateWordpress $job) use ($id) {
            return $job->migrationId === $id && $job->queue === 'default';
        });
    }

    public function test_resume_queues_one_job_and_a_second_resume_does_not(): void
    {
        $this->withoutMiddleware();
        Queue::fake();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
        );
        $migration->status = WordpressMigration::STATUS_FAILED;
        $migration->save();

        $first = $this->postJson('/api/wordpress/migrations/' . $migration->id . '/resume');
        $first->assertStatus(202);
        Queue::assertPushed(MigrateWordpress::class, 1);

        $second = $this->postJson('/api/wordpress/migrations/' . $migration->id . '/resume');
        $second->assertStatus(409);
        Queue::assertPushed(MigrateWordpress::class, 1);
    }

    /**
     * @param list<string> $uris
     */
    private function routeExists(array $uris, string $method, string $uri): bool
    {
        foreach ($uris as $line) {
            if (str_contains($line, $method) && str_ends_with($line, ' ' . $uri)) {
                return true;
            }
        }

        return false;
    }
}
