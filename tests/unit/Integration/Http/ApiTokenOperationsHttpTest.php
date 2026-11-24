<?php

namespace Tests\Integration\Http;

use App\Auth\ApiSurface;
use App\Http\Controllers\ApiTokenController;
use OpenApi\Attributes as OA;
use ReflectionMethod;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The panel reads which REST operations there are before it limits an API token to some of them.
 */
class ApiTokenOperationsHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
    }

    public function test_the_catalogue_describes_every_operation(): void
    {
        $data = $this->getJson('/api/api-tokens/operations')->assertStatus(200)->json('data');

        $byKey = array_column($data, null, 'key');

        $this->assertSame(
            ['key' => 'GET /projects/{username}', 'group' => 'projects', 'method' => 'GET', 'path' => '/projects/{username}', 'summary' => 'Get a project', 'access' => 'read', 'deletes' => false, 'project_scoped' => true],
            $byKey['GET /projects/{username}'],
        );
        $this->assertSame('write', $byKey['POST /projects']['access']);
        $this->assertTrue($byKey['DELETE /projects/{username}']['deletes']);
        $this->assertFalse($byKey['PUT /projects/{username}/suspend']['project_scoped']);
        $this->assertFalse($byKey['GET /api-tokens/operations']['project_scoped']);
        $this->assertTrue($byKey['GET /system/info']['project_scoped']);

        $this->assertSame(ApiSurface::all(), array_column($data, 'key'));
        $this->assertArrayNotHasKey('GET /users', $byKey);
    }

    public function test_an_operation_is_summarised_as_it_is_documented(): void
    {
        $byKey = array_column($this->getJson('/api/api-tokens/operations')->json('data'), null, 'key');

        $this->assertSame('List the REST operations an API token can be limited to', $byKey['GET /api-tokens/operations']['summary']);
        $this->assertSame('Delete a project and everything in it', $byKey['DELETE /projects/{username}']['summary']);
        $this->assertNull($byKey['GET /projects/{username}/source-inspection']['summary']);
    }

    public function test_the_order_is_by_group_then_key(): void
    {
        $data = $this->getJson('/api/api-tokens/operations')->json('data');

        $sorted = $data;
        usort($sorted, fn (array $a, array $b): int => [$a['group'], $a['key']] <=> [$b['group'], $b['key']]);

        $this->assertSame($sorted, $data);
    }

    public function test_a_listed_key_can_be_given_to_a_token(): void
    {
        $keys = array_column($this->getJson('/api/api-tokens/operations')->json('data'), 'key');

        $this->postJson('/api/api-tokens', ['name' => 'ci', 'routes' => array_slice($keys, 0, 2)])
            ->assertStatus(201)
            ->assertJsonPath('data.routes', array_slice($keys, 0, 2));
    }

    public function test_it_is_documented_and_not_an_mcp_tool(): void
    {
        $operation = (new ReflectionMethod(ApiTokenController::class, 'operations'))->getAttributes(OA\Get::class)[0]->newInstance();

        $this->assertSame('/api-tokens/operations', $operation->path);
        $this->assertSame(['mcp-hide' => true], $operation->x);
    }
}
