<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\DeployHooks;
use App\Models\DeployHook;

/**
 * `POST /projects/{username}/git/deploy-hook` end to end -- the route, the
 * form request, the controller and the project's own notion of "connected to
 * git" -- with only bearer authentication switched off.
 */
class CreateDeployHookEndpointTest extends DeployHookTestCase
{
    private const URL = '/api/projects/alice/git/deploy-hook';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_it_creates_the_hook_and_shows_the_secret_once(): void
    {
        $this->user('main');

        $response = $this->postJson(self::URL);

        $response->assertStatus(201);
        $response->assertJsonPath('data.created', true);
        $response->assertJsonPath('data.path', 'project');
        $this->assertMatchesRegularExpression('#^https://203\.0\.113\.10/hooks/[0-9a-f]{40}$#', (string) $response->json('data.url'));
        $secret = (string) $response->json('data.secret');
        $this->assertNotSame('', $secret);
        $this->assertSame($secret, DeployHook::firstOrFail()->secret());
    }

    public function test_a_self_signed_engine_carries_instructions_for_every_supported_provider(): void
    {
        $this->user('main');

        $response = $this->postJson(self::URL);

        $tls = $response->json('data.tls');
        $this->assertSame('self_signed', $tls['state']);
        $this->assertEqualsCanonicalizing(
            ['github', 'gitlab', 'bitbucket-cloud', 'bitbucket-data-center'],
            array_keys($tls['instructions']),
        );
    }

    public function test_a_provider_on_create_narrows_the_instructions_to_it(): void
    {
        $this->user('main');

        $response = $this->postJson(self::URL, ['provider' => 'gitlab']);

        $this->assertSame(['gitlab'], array_keys($response->json('data.tls.instructions')));
    }

    public function test_an_unsupported_provider_is_rejected(): void
    {
        $this->user('main');

        $this->postJson(self::URL, ['provider' => 'not-a-real-host'])->assertStatus(422);
    }

    public function test_the_response_warns_that_pushes_force_the_checkout_to_match_the_repository(): void
    {
        $this->user('main');

        $response = $this->postJson(self::URL);

        $response->assertJsonPath('data.warning', DeployHooks::FORCE_PULL_WARNING);
    }

    public function test_a_site_git_checkout_gets_a_hook_without_the_forced_pull_warning(): void
    {
        $this->siteGitUser();

        $created = $this->postJson(self::URL, ['path' => 'public_html']);
        $again = $this->postJson(self::URL, ['path' => 'public_html']);

        $created->assertStatus(201);
        $created->assertJsonPath('data.path', 'public_html');
        $this->assertNotSame('', (string) $created->json('data.secret'));
        // A push fast-forwards this checkout and never overwrites it, so the
        // warning that it does would be false.
        $this->assertArrayNotHasKey('warning', $created->json('data'));
        $again->assertStatus(200);
        $this->assertArrayNotHasKey('warning', $again->json('data'));
        $this->assertSame(1, DeployHook::count());
    }

    public function test_a_site_git_checkout_that_is_not_connected_is_422(): void
    {
        $this->user('main', ['git_repo' => '']);

        $this->postJson(self::URL, ['path' => 'public_html'])->assertStatus(422);
        $this->assertSame(0, DeployHook::count());
    }

    public function test_calling_it_again_returns_the_same_hook_without_the_secret(): void
    {
        $this->user('main');

        $first = $this->postJson(self::URL);
        $second = $this->postJson(self::URL);

        $second->assertStatus(200);
        $second->assertJsonPath('data.created', false);
        $second->assertJsonPath('data.url', $first->json('data.url'));
        $this->assertArrayNotHasKey('secret', $second->json('data'));
        $second->assertJsonPath('data.warning', DeployHooks::FORCE_PULL_WARNING);
        $this->assertSame(1, DeployHook::count());
    }

    public function test_a_project_that_is_not_connected_to_git_is_422(): void
    {
        $this->user('main', ['git_repo' => '']);

        $response = $this->postJson(self::URL);

        $response->assertStatus(422);
        $this->assertSame(0, DeployHook::count());
    }

    public function test_an_unknown_project_is_404(): void
    {
        $this->postJson('/api/projects/nobody/git/deploy-hook')->assertStatus(404);
    }

    public function test_the_legacy_users_prefix_reaches_it_too(): void
    {
        $this->user('main');

        $this->postJson('/api/users/alice/git/deploy-hook')->assertStatus(201);
    }
}
