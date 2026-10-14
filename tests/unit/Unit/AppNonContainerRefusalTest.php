<?php

namespace Tests\Unit;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every app/* endpoint refuses a classic project, and the refusal names the
 * operation that was asked for: app_info used to be told about user management.
 */
class AppNonContainerRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        $this->withoutMiddleware(Authenticate::class);
        $this->classicProject();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_app_info_names_app_info(): void
    {
        $this->getJson('/api/projects/alice/app/info')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'App info works only on container projects (template dind).']);
    }

    public function test_app_roles_names_role_listing(): void
    {
        $this->getJson('/api/projects/alice/app/roles')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'App role listing works only on container projects (template dind).']);
    }

    public function test_app_users_still_name_user_management(): void
    {
        $this->getJson('/api/projects/alice/app/users')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'App user management works only on container projects (template dind).']);
    }

    private function classicProject(): void
    {
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => 'php']);
        $user->save();
    }
}
