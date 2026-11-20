<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System\Services\Webserver;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/** PUT /projects/{project}/unsuspend makes the project active and rebuilds the vhosts, saying whether the reload waits. */
class ProjectUnsuspendHttpTest extends TestCase
{
    use InMemoryDatabase;

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->answer('compose.serviceContainers', json_encode(['Labels' => 'com.panelalpha.webserver=nginx-proxy']));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_a_suspended_project_is_made_active_and_the_proxy_reloaded(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $user->status = 'suspended';
        $user->save();

        $this->putJson('/api/projects/shop/unsuspend')
            ->assertOk()
            ->assertJsonPath('data.username', 'shop')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('reload_pending', false);

        $this->assertSame('active', User::findByUsernameOrFail('shop')->status);
        $this->assertContains('compose.nginxReload', $this->broker->actions());
    }

    public function test_an_unknown_project_is_not_found(): void
    {
        $this->putJson('/api/projects/nobody/unsuspend')->assertNotFound();

        $this->assertSame([], $this->broker->requests());
    }
}
