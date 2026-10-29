<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /wp/database/import and /export: a file outside the project is a 422
 * on `file`, answered before WP-CLI or the account's containers are touched.
 */
class WpDatabaseFileHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_php_hosting_refuses_a_file_outside_the_home_on_the_field(): void
    {
        $user = $this->makeUser('wpdbf-php');

        foreach (['import', 'export'] as $action) {
            $this->postJson("/api/projects/{$user->username}/wp/database/{$action}", ['file' => '/var/www/html/../../../etc/passwd'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('file')
                ->assertJsonPath('problems.0.code', 'file_outside_project');
        }
    }

    public function test_a_container_project_refuses_a_file_outside_its_mount_on_the_field(): void
    {
        $user = $this->makeUser('wpdbf-dind', ['template' => 'dind']);

        foreach (['import', 'export'] as $action) {
            $this->postJson("/api/projects/{$user->username}/wp/database/{$action}", ['file' => '/etc/passwd'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('file')
                ->assertJsonPath('problems.0.field', 'file');
        }
    }
}
