<?php

namespace Tests\Integration\Http;

use App\Http\Controllers\ApiTokenController;
use App\OpenApi\EngineApiDocument;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;
use ReflectionMethod;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The panel describes the REST API from the engine's own OpenAPI document, which an install never generates by itself.
 */
class ApiTokenOpenApiHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
        $this->dir = base_path('storage/framework/testing/openapi-'.bin2hex(random_bytes(4)));
        File::makeDirectory($this->dir.'/cache', 0755, true);
        config(['l5-swagger.defaults.paths.docs' => $this->dir.'/cache', 'system.version' => '2.4.0']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_answers_the_document_l5_swagger_generates(): void
    {
        $response = $this->get('/api/api-tokens/openapi')->assertStatus(200);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $document = json_decode((string) $response->getContent(), true);
        $this->assertIsString($document['openapi']);
        $this->assertArrayHasKey('get', $document['paths']['/projects']);
        $this->assertArrayHasKey('get', $document['paths']['/api-tokens/openapi']);

        config(['l5-swagger.defaults.paths.docs' => $this->dir.'/artisan']);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));
        $this->assertSame(json_decode(File::get($this->dir.'/artisan/api-docs.json'), true), $document);
    }

    public function test_a_second_request_is_answered_from_storage(): void
    {
        $this->get('/api/api-tokens/openapi')->assertStatus(200);

        $cached = $this->dir.'/cache/engine-2.4.0.json';
        $this->assertFileExists($cached);
        File::put($cached, '{"openapi":"3.0.0","paths":{"/cached":{}}}');

        $this->get('/api/api-tokens/openapi')->assertStatus(200)->assertExactJson(['openapi' => '3.0.0', 'paths' => ['/cached' => []]]);
        $this->assertSame([], File::glob($this->dir.'/cache/.generating-*'));
    }

    public function test_an_update_generates_it_again(): void
    {
        File::put($this->dir.'/cache/engine-2.3.9.json', '{"openapi":"3.0.0","paths":{"/old":{}}}');
        config(['system.version' => '2.3.9']);
        $this->get('/api/api-tokens/openapi')->assertStatus(200)->assertJsonPath('paths./old', []);

        config(['system.version' => '2.4.0']);
        $this->get('/api/api-tokens/openapi')->assertStatus(200)->assertJsonStructure(['paths' => ['/projects']]);

        $this->assertSame([$this->dir.'/cache/engine-2.4.0.json'], File::glob($this->dir.'/cache/engine-*.json'));
    }

    public function test_a_failed_generation_answers_500_and_stores_nothing(): void
    {
        config(['l5-swagger.documentations.default.paths.annotations' => [$this->dir.'/no-such-code']]);

        $this->get('/api/api-tokens/openapi')
            ->assertStatus(500)
            ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'The API document could not be generated: '));

        $this->assertSame([], File::glob($this->dir.'/cache/engine-*.json*'));
        $this->assertSame([], File::glob($this->dir.'/cache/.generating-*'));
    }

    public function test_storage_it_cannot_write_answers_500(): void
    {
        File::put($this->dir.'/file', '');
        config(['l5-swagger.defaults.paths.docs' => $this->dir.'/file/api-docs']);

        $this->get('/api/api-tokens/openapi')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'The API document could not be generated: '.$this->dir.'/file/api-docs could not be created']);
    }

    public function test_the_cache_is_named_by_the_engine_version(): void
    {
        config(['system.version' => '2.5.0-rc.1']);

        $this->assertSame($this->dir.'/cache/engine-2.5.0-rc.1.json', (new EngineApiDocument)->path());
    }

    public function test_it_is_documented_and_not_an_mcp_tool(): void
    {
        $operation = (new ReflectionMethod(ApiTokenController::class, 'openapi'))->getAttributes(OA\Get::class)[0]->newInstance();

        $this->assertSame('/api-tokens/openapi', $operation->path);
        $this->assertSame(['mcp-hide' => true], $operation->x);
    }
}
