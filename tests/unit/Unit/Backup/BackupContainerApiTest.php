<?php

namespace Tests\Unit\Backup;

use App\Http\Middleware\Authenticate;
use App\Jobs\DeleteBackupContainer;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `/api/backup-containers`: plain storage records, so every endpoint but
 * `test` runs against the database alone.
 */
class BackupContainerApiTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    private function container(string $name, string $driver = 'local', ?array $credentials = null): BackupContainer
    {
        return BackupContainer::create(['name' => $name, 'driver' => $driver, 'location' => "/opt/panelalpha/backups/{$name}", 'credentials' => $credentials]);
    }

    public function test_the_list_is_ordered_by_name_and_never_shows_credentials(): void
    {
        $this->container('zeta', 'sftp', ['host' => 'h', 'username' => 'u', 'password' => 'secret']);
        $this->container('alpha');

        $response = $this->getJson('/api/backup-containers')->assertOk();

        $this->assertSame(['alpha', 'zeta'], array_column($response->json('data'), 'name'));
        $this->assertSame([false, true], array_column($response->json('data'), 'has_credentials'));
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function test_a_missing_container_is_a_404(): void
    {
        $this->getJson('/api/backup-containers/404')->assertNotFound();
        $this->putJson('/api/backup-containers/404', ['name' => 'x'])->assertNotFound();
        $this->deleteJson('/api/backup-containers/404')->assertNotFound();
    }

    public function test_a_local_container_is_created_without_credentials(): void
    {
        $this->postJson('/api/backup-containers', ['name' => 'disk', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/disk'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'disk')
            ->assertJsonPath('data.has_credentials', false);

        $this->assertNull(BackupContainer::query()->where('name', 'disk')->firstOrFail()->credentials);
    }

    public function test_a_local_location_in_the_engine_directory_is_refused_on_create_and_update(): void
    {
        $box = $this->container('box');
        $inside = '/opt/panelalpha/shared-hosting/backups';

        $this->postJson('/api/backup-containers', ['name' => 'disk', 'driver' => 'local', 'location' => $inside])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');
        $this->putJson("/api/backup-containers/{$box->id}", ['location' => $inside])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');

        $this->assertNull(BackupContainer::query()->where('name', 'disk')->first());
        $this->assertSame('/opt/panelalpha/backups/box', $box->fresh()->location);
    }

    public function test_a_failing_store_test_names_what_failed_without_its_credentials(): void
    {
        $box = BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'my-bucket', 'credentials' => [
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI-K7MDENG',
            'endpoint' => 'http://127.0.0.1:1',
            'use_path_style_endpoint' => true,
        ]]);

        $response = $this->postJson("/api/backup-containers/{$box->id}/test")->assertUnprocessable();

        $this->assertStringContainsString('http://127.0.0.1:1/my-bucket/', (string) $response->json('message'));
        $this->assertStringNotContainsString('wJalrXUtnFEMI-K7MDENG', (string) $response->getContent());
    }

    public function test_switching_to_local_drops_the_credentials(): void
    {
        $box = $this->container('box', 'sftp', ['host' => 'h', 'username' => 'u']);

        $this->putJson("/api/backup-containers/{$box->id}", ['driver' => 'local', 'location' => '/opt/panelalpha/backups/box'])
            ->assertOk()
            ->assertJsonPath('data.driver', 'local')
            ->assertJsonPath('data.has_credentials', false);

        $this->assertNull($box->fresh()->credentials);
    }

    public function test_an_empty_container_is_deleted_at_once(): void
    {
        $box = $this->container('box');

        $this->deleteJson("/api/backup-containers/{$box->id}")->assertOk()->assertJsonPath('data.name', 'box');

        $this->assertNull(BackupContainer::query()->find($box->id));
    }

    public function test_a_container_with_backups_is_kept_unless_they_are_deleted_too(): void
    {
        Queue::fake();
        $box = $this->container('box');
        $user = $this->makeUser('alice');
        BackupRecord::create(['user_id' => $user->id, 'username' => 'alice', 'container_id' => $box->id]);

        $this->deleteJson("/api/backup-containers/{$box->id}")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Cannot delete backup container while backups exist. Pass delete_backups to remove them with the container.']);
        Queue::assertNothingPushed();

        $this->deleteJson("/api/backup-containers/{$box->id}?delete_backups=1")
            ->assertStatus(202)
            ->assertJsonPath('data.name', 'box')
            ->assertJsonStructure(['data', 'task_id']);
        Queue::assertPushed(DeleteBackupContainer::class, fn (DeleteBackupContainer $job): bool => $job->containerId === $box->id);

        $this->assertNotNull(BackupContainer::query()->find($box->id), 'the job deletes it, not the request');
    }
}
