<?php

namespace Tests\Unit;

use App\Http\Middleware\Authenticate;
use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\Projects\AppCredentialsGetTool;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * An app's generated login is stored on the project like env_vars (encrypted),
 * pointed to by GET /projects/{username} without a value, and returned by its
 * own endpoint and a credential-class MCP tool.
 */
class AppCredentialsDetailsTest extends TestCase
{
    use InMemoryDatabase;

    private const STORED = [
        'fields' => [
            'SONARR_ADMIN_USER' => ['kind' => 'username', 'value' => 'admin'],
            'SONARR_ADMIN_PASSWORD' => ['kind' => 'password', 'value' => 'Zq8vN2kLp4Xw7Rt1Ys6Hb3Jm'],
        ],
        'login_path' => '/login',
        'created_at' => '2026-09-30T10:00:00+00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        // The requests cache every setting statically; later tests must not read this database's.
        (new \ReflectionProperty(Setting::class, 'allSettings'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_they_are_encrypted_at_rest_and_read_back_whole(): void
    {
        $user = new User();
        $user->setAppCredentials(self::STORED);

        $raw = (string) $user->getAttributes()['details'];
        $this->assertStringNotContainsString('Zq8vN2kLp4Xw7Rt1Ys6Hb3Jm', $raw);
        $this->assertStringNotContainsString('SONARR_ADMIN_PASSWORD', $raw);
        $this->assertSame(self::STORED, $user->getAppCredentials());

        // A later, unrelated write keeps them and keeps them encrypted.
        $user->setDetails(['deployment_status' => 'success']);
        $this->assertStringNotContainsString('Zq8vN2kLp4Xw7Rt1Ys6Hb3Jm', (string) $user->getAttributes()['details']);
        $this->assertSame(self::STORED, $user->getAppCredentials());

        $user->setAppCredentials(null);
        $this->assertNull($user->getAppCredentials());
    }

    public function test_the_project_points_to_them_and_the_endpoint_returns_them(): void
    {
        $user = $this->makeUser('acsonarr1', ['template' => 'dind']);
        $this->makeMainDomain($user, 'tv.example.com');
        $user->setAppCredentials(self::STORED);
        $user->save();

        $project = $this->getJson('/api/projects/acsonarr1')->assertOk()->json('data');
        $this->assertSame([
            'available' => true,
            'fields' => [
                ['name' => 'SONARR_ADMIN_USER', 'kind' => 'username'],
                ['name' => 'SONARR_ADMIN_PASSWORD', 'kind' => 'password'],
            ],
            'login_url' => 'https://tv.example.com/login',
            'endpoint' => '/api/projects/acsonarr1/app-credentials',
        ], $project['app_credentials']);
        $this->assertArrayNotHasKey('app_credentials', $project['details']);
        $this->assertStringNotContainsString('Zq8vN2kLp4Xw7Rt1Ys6Hb3Jm', (string) json_encode($project));

        // Repeatable: the engine keeps the values to deliver them on every deploy.
        foreach ([1, 2] as $_) {
            $this->getJson('/api/projects/acsonarr1/app-credentials')->assertOk()->assertExactJson(['data' => [
                'available' => true,
                'login_url' => 'https://tv.example.com/login',
                'created_at' => '2026-09-30T10:00:00+00:00',
                'fields' => [
                    ['name' => 'SONARR_ADMIN_USER', 'kind' => 'username', 'value' => 'admin'],
                    ['name' => 'SONARR_ADMIN_PASSWORD', 'kind' => 'password', 'value' => 'Zq8vN2kLp4Xw7Rt1Ys6Hb3Jm'],
                ],
            ]]);
        }
    }

    public function test_a_project_without_them_says_so_on_both(): void
    {
        $this->makeUser('plain', ['template' => 'dind']);

        $this->assertSame(
            ['available' => false, 'fields' => [], 'login_url' => null, 'endpoint' => '/api/projects/plain/app-credentials'],
            $this->getJson('/api/projects/plain')->assertOk()->json('data.app_credentials')
        );
        $this->getJson('/api/projects/plain/app-credentials')->assertOk()->assertExactJson(['data' => [
            'available' => false, 'login_url' => null, 'created_at' => null, 'fields' => [],
        ]]);
        $this->getJson('/api/projects/nobody/app-credentials')->assertNotFound();
    }

    public function test_the_endpoint_is_routed_under_both_prefixes(): void
    {
        foreach (['api/projects/acsonarr1/app-credentials', 'api/users/acsonarr1/app-credentials'] as $uri) {
            $route = Route::getRoutes()->match(Request::create('/' . $uri, 'GET'));
            $this->assertSame('App\Http\Controllers\User\AppCredentialsController@show', $route->getActionName());
        }
    }

    public function test_the_mcp_tool_reads_the_endpoint_and_is_withheld_from_readonly(): void
    {
        $tool = new AppCredentialsGetTool();
        $this->assertSame('app_credentials_get', $tool->name());
        $this->assertSame('GET', $tool->httpMethod());
        $path = new \ReflectionMethod($tool, 'path');
        $this->assertSame('/projects/{username}/app-credentials', $path->invoke($tool));

        $readonly = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'readonly']);
        $this->assertSame([], $readonly->filter([AppCredentialsGetTool::class]));
        $this->assertSame('secret', $readonly->accessOf(AppCredentialsGetTool::class));

        $modify = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'modify']);
        $this->assertSame([AppCredentialsGetTool::class], $modify->filter([AppCredentialsGetTool::class]));
    }
}
