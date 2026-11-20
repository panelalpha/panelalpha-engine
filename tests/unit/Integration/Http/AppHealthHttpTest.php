<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Facade;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/** GET /projects/{project}/app/health on a container project, and the stored-secrets check it adds when one cannot be read. */
class AppHealthHttpTest extends TestCase
{
    use InMemoryDatabase;

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->useAppKey(str_repeat('k', 32));
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
    }

    protected function tearDown(): void
    {
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_an_app_with_no_ports_has_nothing_to_probe(): void
    {
        $this->makeUser('shop', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001]);
        $this->broker->answer('files.isFile', exit: 1, match: '/home/shop/project/');

        $this->getJson('/api/projects/shop/app/health?timeout=2&attempts=1')
            ->assertOk()
            ->assertExactJson(['data' => ['healthy' => null, 'ports' => []]]);
    }

    public function test_an_env_file_that_leaves_the_home_is_not_read(): void
    {
        $this->makeUser('shop', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001]);
        $this->broker->answer('files.existingRealPaths', "/home/shop\n/etc/shadow\n");

        $this->getJson('/api/projects/shop/app/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.error', "could not read the application compose file: Refusing to read '/home/shop/project/.env': it resolves to a file outside /home/shop.");
    }

    public function test_a_secret_the_key_cannot_decode_is_reported_as_a_check(): void
    {
        $this->makeUser('shop', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001, 'git_token' => 'ghp_old']);
        $this->useAppKey(str_repeat('x', 32));

        $this->getJson('/api/projects/shop/app/health')
            ->assertOk()
            ->assertJsonPath('data.checks.0.title', 'Stored secrets cannot be decoded.')
            ->assertJsonPath('data.checks.0.evidence.secrets', ['git_token']);
    }

    public function test_probe_limits_are_held_to_their_range(): void
    {
        $this->makeUser('shop', ['template' => 'dind']);

        $this->getJson('/api/projects/shop/app/health?timeout=60&attempts=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timeout', 'attempts']);
    }

    public function test_a_project_that_is_not_a_container_project_is_refused(): void
    {
        $this->makeUser('classic', ['template' => 'default']);

        $this->getJson('/api/projects/classic/app/health')
            ->assertForbidden()
            ->assertExactJson(['message' => 'App health checks work only on container projects (template dind).']);
    }

    private function useAppKey(string $key): void
    {
        config(['app.key' => 'base64:' . base64_encode($key)]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
    }
}
