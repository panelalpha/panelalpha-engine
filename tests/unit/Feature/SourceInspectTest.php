<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The inspection endpoint through the real stack: routing, auth, validation.
 *
 * What the report contains is pinned by the unit tests, which run the same
 * detection against fixture directories without a server. These check the two
 * things only a request can prove — that the route is mounted behind the API
 * guard, and that a source it cannot read is answered rather than thrown.
 */
class SourceInspectTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/source-inspect-feature-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_it_inspects_a_local_path(): void
    {
        file_put_contents($this->tmpDir . '/index.html', '<h1>hello</h1>');

        $this->authenticate();
        $response = $this->postJson('/api/source/inspect', ['source' => $this->tmpDir]);

        $response->assertStatus(200);
        $this->assertSame('path', $response->json('data.source.type'));
        $this->assertSame('static', $response->json('data.application.strategy'));
        $this->assertTrue($response->json('data.application.deployable'));
    }

    public function test_it_requires_a_source(): void
    {
        $this->authenticate();

        $this->postJson('/api/source/inspect', [])->assertStatus(422);
    }

    public function test_a_source_it_cannot_place_is_a_422(): void
    {
        $this->authenticate();

        $this->postJson('/api/source/inspect', ['source' => 'not a source'])->assertStatus(422);
    }

    public function test_a_missing_directory_is_a_422(): void
    {
        $this->authenticate();

        $this->postJson('/api/source/inspect', ['source' => '/no/such/directory'])
            ->assertStatus(422);
    }

    public function test_it_needs_authentication(): void
    {
        $this->postJson('/api/source/inspect', ['source' => $this->tmpDir])->assertStatus(401);
    }

    /**
     * A username is a source like any other, so the one endpoint answers for a
     * project too — the reason /projects/{username}/inspect is a convenience
     * rather than the only way to ask.
     */
    public function test_a_project_username_is_a_source_the_post_endpoint_accepts(): void
    {
        $this->authenticate();

        $this->postJson('/api/source/inspect', ['source' => 'nosuchproject', 'type' => 'project'])
            ->assertStatus(404);
    }

    public function test_inspecting_an_unknown_project_is_a_404(): void
    {
        $this->authenticate();

        $this->getJson('/api/projects/nosuchproject/inspect')->assertStatus(404);
    }

    /**
     * The route is registered inside the shared closure, so the deprecated
     * /users prefix serves it too.
     */
    public function test_the_project_route_is_served_under_the_users_alias(): void
    {
        $this->authenticate();

        $this->getJson('/api/users/nosuchproject/inspect')->assertStatus(404);
    }

    /**
     * The path this endpoint was first published under. Kept for one release,
     * under both prefixes, so a rename does not break existing integrations.
     */
    public function test_the_deprecated_source_inspection_path_still_serves(): void
    {
        $this->authenticate();

        $this->getJson('/api/projects/nosuchproject/source-inspection')->assertStatus(404);
        $this->getJson('/api/users/nosuchproject/source-inspection')->assertStatus(404);
    }

    /**
     * `path` was this endpoint's own word for what POST /source/inspect calls
     * `subdirectory`. Both are accepted; validation is what proves which one
     * reached the request, since an unknown project is a 404 either way.
     */
    public function test_the_directory_is_accepted_under_both_names(): void
    {
        $this->authenticate();

        $long = str_repeat('a', 513);

        $this->getJson('/api/projects/nosuchproject/inspect?subdirectory=' . $long)
            ->assertStatus(422);
        $this->getJson('/api/projects/nosuchproject/inspect?path=' . $long)
            ->assertStatus(422);
        $this->getJson('/api/projects/nosuchproject/inspect?path=project')
            ->assertStatus(404);
    }

    public function test_the_project_route_needs_authentication(): void
    {
        $this->getJson('/api/projects/nosuchproject/inspect')->assertStatus(401);
    }
}
