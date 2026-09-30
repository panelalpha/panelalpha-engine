<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\BackupContainer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A findOrFail() miss is a 404 that names the thing, never the model class. */
class ModelNotFoundResponseTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_a_missing_backup_container_does_not_expose_the_model_class(): void
    {
        $response = $this->getJson('/api/backup-containers/999');

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Backup container not found']);
        $this->assertStringNotContainsString('Models', (string) $response->getContent());
    }

    public function test_the_update_request_lookup_answers_the_same_way(): void
    {
        $response = $this->putJson('/api/backup-containers/999', ['name' => 'x']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Backup container not found']);
    }

    public function test_a_missing_ip_subnet_is_named_in_words(): void
    {
        Route::get('/api/test-missing-subnet', static function () {
            throw (new ModelNotFoundException())->setModel(\App\Models\IpSubnet::class, [7]);
        });

        $this->getJson('/api/test-missing-subnet')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Ip subnet not found']);
    }

    public function test_an_exception_without_a_model_says_not_found(): void
    {
        Route::get('/api/test-missing-anything', static function () {
            throw new ModelNotFoundException();
        });

        $this->getJson('/api/test-missing-anything')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found']);
    }

    public function test_an_existing_backup_container_is_still_returned(): void
    {
        $container = BackupContainer::create([
            'name' => 'local',
            'driver' => 'local',
            'location' => '/backups',
        ]);

        $this->getJson('/api/backup-containers/' . $container->id)->assertStatus(200);
    }
}
