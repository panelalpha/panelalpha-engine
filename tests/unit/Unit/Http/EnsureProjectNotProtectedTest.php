<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\EnsureProjectNotProtected;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A protected project refuses every delete, stop and redeploy that arrives with
 * a token, in front of the controller, so nothing runs and nothing is queued.
 */
class EnsureProjectNotProtectedTest extends TestCase
{
    use InMemoryDatabase;

    private bool $nextCalled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        $this->makeUser('alice', ['template' => 'dind', 'protected' => 'on']);
        $this->makeUser('bob', ['template' => 'dind']);
        $this->makeMainDomain(User::findByUsername('alice'), 'alice.example.test');
        $this->addonDomain(User::findByUsername('alice'), 'addon.alice.example.test');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: array<string, string>}>
     */
    public static function refusedOperations(): array
    {
        return [
            'delete the project' => ['DELETE', 'api/projects/{username}', ['username' => 'alice'], []],
            'change the main domain' => ['PUT', 'api/projects/{username}', ['username' => 'alice'], ['domain' => 'new.example.test']],
            'suspend' => ['PUT', 'api/projects/{username}/suspend', ['username' => 'alice'], []],
            'rebuild' => ['POST', 'api/projects/{username}/rebuild', ['username' => 'alice'], []],
            'deploy an archive' => ['POST', 'api/projects/{username}/deploy-archive', ['username' => 'alice'], []],
            'attach a source' => ['POST', 'api/projects/{username}/source', ['username' => 'alice'], []],
            'git pull' => ['POST', 'api/projects/{username}/git/pull', ['username' => 'alice'], []],
            'change branch' => ['PUT', 'api/projects/{username}/git/change-branch', ['username' => 'alice'], []],
            'git revert' => ['POST', 'api/projects/{username}/git/revert', ['username' => 'alice'], []],
            'env vars, apply defaults to recreate' => ['PUT', 'api/projects/{username}/env-vars', ['username' => 'alice'], []],
            'env vars, apply recreate' => ['PUT', 'api/projects/{username}/env-vars', ['username' => 'alice'], ['apply' => 'recreate']],
            'stop the containers' => ['POST', 'api/projects/{username}/containers/action', ['username' => 'alice'], ['action' => 'stop']],
            'bring the containers down' => ['POST', 'api/projects/{username}/containers/action', ['username' => 'alice'], ['action' => 'down']],
            'restart the containers' => ['POST', 'api/projects/{username}/containers/action', ['username' => 'alice'], ['action' => 'restart']],
            'pull new images' => ['POST', 'api/projects/{username}/containers/action', ['username' => 'alice'], ['action' => 'pull']],
            'stop a service' => ['POST', 'api/projects/{username}/containers/{service}/action', ['username' => 'alice', 'service' => 'web'], ['action' => 'stop']],
            'restart a service' => ['POST', 'api/projects/{username}/containers/{service}/action', ['username' => 'alice', 'service' => 'web'], ['action' => 'restart']],
            'delete the main domain' => ['DELETE', 'api/projects/{username}/domains/{domain}', ['username' => 'alice', 'domain' => 'alice.example.test'], []],
            'restore a backup' => ['POST', 'api/projects/{username}/backups/{id}/restore', ['username' => 'alice', 'id' => '7'], ['confirm' => 'true']],
            'push onto a protected target' => ['POST', 'api/projects/{username}/push', ['username' => 'bob'], ['target' => 'alice']],
        ];
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $body
     */
    #[DataProvider('refusedOperations')]
    public function test_a_token_is_refused_a_refused_operation_on_a_protected_project(
        string $method,
        string $uri,
        array $params,
        array $body
    ): void {
        $response = $this->dispatched($method, $uri, $params, $body);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(EnsureProjectNotProtected::CODE, $response->getData(true)['code']);
        $this->assertStringContainsString('alice', $response->getData(true)['message']);
        $this->assertFalse($this->nextCalled, 'the middleware let the request through');
    }

    public function test_a_tool_call_is_refused_too(): void
    {
        $request = $this->request('DELETE', 'api/projects/{username}', ['username' => 'alice']);
        $request->attributes->set(\App\Mcp\Tools\Api\ApiTool::VIA_ATTRIBUTE, true);

        $response = (new EnsureProjectNotProtected())->handle($request, fn (): Response => new Response('ok', 200));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(EnsureProjectNotProtected::CODE, $response->getData(true)['code']);
    }

    public function test_a_caller_without_a_token_passes(): void
    {
        $this->assertSame(200, $this->statusOf($this->request('DELETE', 'api/projects/{username}', ['username' => 'alice'], [], false)));
    }

    public function test_another_project_is_unaffected(): void
    {
        $this->assertSame(200, $this->statusOf($this->request('DELETE', 'api/projects/{username}', ['username' => 'bob'])));
    }

    public function test_a_read_on_a_protected_project_passes(): void
    {
        $this->assertSame(200, $this->statusOf($this->request('GET', 'api/projects/{username}', ['username' => 'alice'])));
    }

    public function test_a_container_up_is_allowed(): void
    {
        $this->assertSame(200, $this->statusOf($this->request(
            'POST',
            'api/projects/{username}/containers/action',
            ['username' => 'alice'],
            ['action' => 'up']
        )));
    }

    public function test_a_container_up_on_a_compose_project_is_refused(): void
    {
        $this->makeUser('carol', [
            'template' => 'dind',
            'protected' => 'on',
            'deploy_strategy' => Strategies::COMPOSE,
        ]);

        $response = $this->dispatched(
            'POST',
            'api/projects/{username}/containers/action',
            ['username' => 'carol'],
            ['action' => 'up']
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(EnsureProjectNotProtected::CODE, $response->getData(true)['code']);
        $this->assertFalse($this->nextCalled, 'the middleware let the request through');
    }

    public function test_a_container_up_on_a_recipe_project_is_allowed(): void
    {
        $this->makeUser('dave', [
            'template' => 'dind',
            'protected' => 'on',
            'deploy_strategy' => Strategies::LARAVEL,
        ]);

        $this->assertSame(200, $this->statusOf($this->request(
            'POST',
            'api/projects/{username}/containers/action',
            ['username' => 'dave'],
            ['action' => 'up']
        )));
    }

    public function test_a_container_up_on_an_unprotected_compose_project_is_allowed(): void
    {
        $this->makeUser('erin', ['template' => 'dind', 'deploy_strategy' => Strategies::COMPOSE]);

        $this->assertSame(200, $this->statusOf($this->request(
            'POST',
            'api/projects/{username}/containers/action',
            ['username' => 'erin'],
            ['action' => 'up']
        )));
    }

    public function test_a_service_start_is_allowed(): void
    {
        $this->assertSame(200, $this->statusOf($this->request(
            'POST',
            'api/projects/{username}/containers/{service}/action',
            ['username' => 'alice', 'service' => 'web'],
            ['action' => 'start']
        )));
    }

    public function test_env_vars_with_apply_none_are_allowed(): void
    {
        $this->assertSame(200, $this->statusOf($this->request(
            'PUT',
            'api/projects/{username}/env-vars',
            ['username' => 'alice'],
            ['apply' => 'none']
        )));
    }

    public function test_a_project_update_without_a_domain_is_allowed(): void
    {
        $this->assertSame(200, $this->statusOf($this->request(
            'PUT',
            'api/projects/{username}',
            ['username' => 'alice'],
            ['email' => 'alice@example.test']
        )));
    }

    public function test_deleting_another_domain_is_allowed(): void
    {
        $this->assertSame(200, $this->statusOf($this->request(
            'DELETE',
            'api/projects/{username}/domains/{domain}',
            ['username' => 'alice', 'domain' => 'addon.alice.example.test']
        )));
    }

    public function test_a_push_onto_an_unprotected_target_is_allowed(): void
    {
        $this->assertSame(200, $this->statusOf($this->request(
            'POST',
            'api/projects/{username}/push',
            ['username' => 'alice'],
            ['target' => 'bob']
        )));
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $body
     */
    private function dispatched(string $method, string $uri, array $params = [], array $body = []): JsonResponse|Response
    {
        return (new EnsureProjectNotProtected())->handle(
            $this->request($method, $uri, $params, $body),
            function (): Response {
                $this->nextCalled = true;

                return new Response('ok', 200);
            }
        );
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $body
     */
    private function statusOf(Request $request): int
    {
        return (new EnsureProjectNotProtected())->handle($request, fn (): Response => new Response('ok', 200))->getStatusCode();
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $body
     */
    private function request(
        string $method,
        string $uri,
        array $params = [],
        array $body = [],
        bool $withToken = true
    ): Request {
        $request = Request::create('/' . self::pathOf($uri, $params), $method);
        $request->merge($body);
        $request->setUserResolver(fn () => $withToken ? $this->adminWithToken() : new class extends \Illuminate\Foundation\Auth\User {
        });

        $request->setRouteResolver(function () use ($method, $uri, $request): Route {
            $matched = new Route([$method], $uri, []);
            $matched->bind($request);

            return $matched;
        });

        return $request;
    }

    private function adminWithToken(): Admin
    {
        $admin = new Admin(['name' => 'root']);
        $admin->withAccessToken(new PersonalAccessToken([
            'name' => 'test',
            'abilities' => TokenAbilities::build(api: true, mcp: true),
        ]));

        return $admin;
    }

    /** @param array<string, string> $params */
    private static function pathOf(string $uri, array $params): string
    {
        foreach ($params as $name => $value) {
            $uri = str_replace('{' . $name . '}', $value, $uri);
        }

        return $uri;
    }

    private function addonDomain(User $user, string $domain): void
    {
        Domain::make([
            'user_id' => $user->id,
            'domain' => $domain,
            'type' => 'addon',
            'details' => [],
        ])->save();
    }
}
