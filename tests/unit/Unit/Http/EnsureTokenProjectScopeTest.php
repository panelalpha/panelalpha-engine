<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\EnsureTokenMayUseApi;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The project half of the token boundary: a token scoped to some projects may
 * only reach those, where the request names a project by username, domain or
 * task. The username and global cases are in EnsureTokenMayUseApiTest; these
 * three need the database to resolve a domain or a task to its project.
 */
class EnsureTokenProjectScopeTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_scoped_token_may_set_the_php_version_of_its_own_domain(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');

        $this->assertSame(200, $this->through(
            TokenAbilities::build(api: true, mcp: false, projects: ['alice']),
            'api/domains/{domain}/php-version',
            ['domain' => 'alice.example.test'],
        ));
    }

    public function test_a_scoped_token_is_refused_another_projects_domain(): void
    {
        $this->makeUser('alice');
        $this->makeMainDomain($this->makeUser('bob'), 'bob.example.test');

        $this->assertSame(403, $this->through(
            TokenAbilities::build(api: true, mcp: false, projects: ['alice']),
            'api/domains/{domain}/php-version',
            ['domain' => 'bob.example.test'],
        ));
    }

    public function test_a_scoped_token_is_refused_a_domain_no_project_owns(): void
    {
        $this->makeUser('alice');

        $this->assertSame(403, $this->through(
            TokenAbilities::build(api: true, mcp: false, projects: ['alice']),
            'api/domains/{domain}',
            ['domain' => 'nobody.example.test'],
        ));
    }

    public function test_a_scoped_token_may_read_its_own_task(): void
    {
        $task = $this->task('alice');

        $this->assertSame(200, $this->through(
            TokenAbilities::build(api: true, mcp: false, projects: ['alice']),
            'api/tasks/{id}',
            ['id' => (string) $task->id],
        ));
    }

    public function test_a_scoped_token_is_refused_another_projects_task(): void
    {
        $task = $this->task('bob');

        $this->assertSame(403, $this->through(
            TokenAbilities::build(api: true, mcp: false, projects: ['alice']),
            'api/tasks/{id}/cancel',
            ['id' => (string) $task->id],
        ));
    }

    public function test_a_scoped_token_is_refused_an_engine_task(): void
    {
        $task = $this->task(null);

        $this->assertSame(403, $this->through(
            TokenAbilities::build(api: true, mcp: false, projects: ['alice']),
            'api/tasks/{id}',
            ['id' => (string) $task->id],
        ));
    }

    private function task(?string $username): Task
    {
        return Task::start(
            jobType: 'App\\Jobs\\DeployProject',
            queue: 'default',
            username: $username,
        );
    }

    /**
     * @param array<int, string>               $abilities
     * @param array<string, string>            $params
     */
    private function through(array $abilities, string $uri, array $params): int
    {
        $admin = (new Admin(['name' => 'root']))
            ->withAccessToken(new PersonalAccessToken(['name' => 'test', 'abilities' => $abilities]));

        $path = $uri;
        foreach ($params as $name => $value) {
            $path = str_replace('{' . $name . '}', $value, $path);
        }

        $request = Request::create('/' . $path, 'GET');
        $request->setUserResolver(fn () => $admin);

        $route = new Route(['GET'], $uri, []);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return (new EnsureTokenMayUseApi())
            ->handle($request, fn (): Response => new Response('ok', 200))
            ->getStatusCode();
    }
}
