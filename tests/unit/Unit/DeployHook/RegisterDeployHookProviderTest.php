<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\GitlabProvider;
use App\Lib\DeployHook\DeployHookActions;
use App\Models\DeployHook;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Registering a Deploy Hook as a webhook at the git provider the checkout came
 * from: the documented call per provider, the update on rotate, the delete on
 * destroy, and the refusals that still leave the engine's own hook in place.
 * No provider is ever called for real.
 */
class RegisterDeployHookProviderTest extends DeployHookTestCase
{
    private const URL = '/api/projects/alice/git/deploy-hook';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    /**
     * @param array<string, mixed> $details
     */
    private function project(string $repoUrl, ?string $token = 'a-provider-token', array $details = []): User
    {
        return $this->user('main', array_merge([
            'git_repo' => $repoUrl,
            'git_token' => $token,
        ], $details));
    }

    // -- create -------------------------------------------------------------

    public function test_github_receives_the_documented_create_call(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 4711], 201)]);
        $this->project('https://github.com/octocat/Hello-World.git', 'ghp-token');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '4711');
        $this->assertNotNull($response->json('data.registration.registered_at'));

        $secret = (string) $response->json('data.secret');
        $url = (string) $response->json('data.url');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/octocat/Hello-World/hooks'
            && $request->hasHeader('Authorization', 'Bearer ghp-token')
            && $request['name'] === 'web'
            && $request['events'] === ['push']
            && $request['config']['url'] === $url
            && $request['config']['content_type'] === 'json'
            && $request['config']['secret'] === $secret);

        $hook = DeployHook::firstOrFail();
        $this->assertSame('4711', $hook->provider_hook_id);
        $this->assertSame('github', $hook->provider);
        $this->assertNotNull($hook->registered_at);
    }

    public function test_gitlab_receives_the_documented_create_call(): void
    {
        Http::fake(['gitlab.com/*' => Http::response(['id' => 88], 201)]);
        $this->project('https://gitlab.com/acme/widgets.git', 'glpat-token');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '88');

        $secret = (string) $response->json('data.secret');
        $url = (string) $response->json('data.url');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gitlab.com/api/v4/projects/acme%2Fwidgets/hooks'
            && $request->hasHeader('PRIVATE-TOKEN', 'glpat-token')
            && $request['push_events'] === true
            && $request['signing_token'] === $secret
            && $request['token'] === GitlabProvider::tokenFor($secret)
            && $request['url'] === $url);
    }

    public function test_bitbucket_cloud_receives_the_documented_create_call(): void
    {
        Http::fake(['api.bitbucket.org/*' => Http::response(['uuid' => '{d6b3a4f7-0000}'], 201)]);
        $this->project('https://bitbucket.org/acme/widgets.git', 'bb-token');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '{d6b3a4f7-0000}');

        $secret = (string) $response->json('data.secret');
        $url = (string) $response->json('data.url');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.bitbucket.org/2.0/repositories/acme/widgets/hooks'
            && $request->hasHeader('Authorization', 'Bearer bb-token')
            && $request['events'] === ['repo:push']
            && $request['secret'] === $secret
            && $request['url'] === $url);
    }

    public function test_a_site_git_checkout_registers_with_its_own_token(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 3], 201)]);
        $this->user('main', [
            'git_repo' => '',
            'git_branch' => null,
            'site_git' => ['public_html' => [
                'repo_url' => 'https://github.com/octocat/Hello-World.git',
                'branch' => 'main',
                'token' => 'site-token',
            ]],
        ]);

        $response = $this->postJson(self::URL, ['path' => 'public_html', 'register' => true]);

        $response->assertStatus(201);
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer site-token'));
        $this->assertSame('3', DeployHook::firstOrFail()->provider_hook_id);
    }

    public function test_registering_an_already_registered_hook_does_not_create_a_second_webhook(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 9], 201)]);
        $this->project('https://github.com/octocat/Hello-World.git');

        $first = $this->postJson(self::URL, ['register' => true]);
        $second = $this->postJson(self::URL, ['register' => true]);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $second->assertJsonPath('data.registration.provider_hook_id', '9');
        Http::assertSentCount(1);
    }

    public function test_show_reports_the_registration_and_no_registration_alike(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 4711], 201)]);
        $user = $this->project('https://github.com/octocat/Hello-World.git');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $shown = $this->getJson(self::URL);

        $shown->assertStatus(200);
        $shown->assertJsonPath('data.registration.provider_hook_id', '4711');
        $shown->assertJsonStructure(['data' => ['registration' => ['provider_hook_id', 'registered_at']]]);

        $this->deleteJson(self::URL)->assertStatus(204);
        $this->hook($user);
        $this->getJson(self::URL)->assertJsonPath('data.registration', null);
    }

    // -- refusals -----------------------------------------------------------

    public function test_register_without_a_checkout_token_is_422_provider_token_missing(): void
    {
        Http::fake();
        $this->project('https://github.com/octocat/Hello-World.git', null);

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'provider_token_missing');
        Http::assertNothingSent();
        $this->assertSame(1, DeployHook::count());
    }

    public function test_a_token_without_hook_scope_is_422_provider_permission_denied_and_the_hook_stays(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 403)]);
        $this->project('https://github.com/octocat/Hello-World.git');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'provider_permission_denied');
        $this->assertSame(1, DeployHook::count());
        $this->assertFalse(DeployHook::firstOrFail()->isRegistered());
    }

    /**
     * A create after a refused registration repeats the hook the refusal kept
     * (200, `created: false`); only deleting it first makes the next create a
     * fresh one (201, `created: true`).
     */
    public function test_a_create_after_a_refused_registration_is_a_repeat_not_a_fresh_create(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 403)]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $refused = $this->postJson(self::URL, ['register' => true]);
        $refused->assertStatus(422);

        $repeat = $this->postJson(self::URL);
        $repeat->assertStatus(200);
        $repeat->assertJsonPath('data.created', false);
        $repeat->assertJsonPath('data.registration', null);

        $this->deleteJson(self::URL)->assertStatus(204);
        $fresh = $this->postJson(self::URL);
        $fresh->assertStatus(201);
        $fresh->assertJsonPath('data.created', true);
        $fresh->assertJsonPath('data.registration', null);
    }

    public function test_a_provider_that_answers_an_error_is_502_and_the_hook_stays(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'nope'], 500)]);
        $this->project('https://github.com/octocat/Hello-World.git');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(502);
        $this->assertSame(1, DeployHook::count());
    }

    public function test_a_provider_that_cannot_be_reached_is_502_and_the_hook_stays(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->project('https://github.com/octocat/Hello-World.git');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(502);
        $this->assertSame(1, DeployHook::count());
    }

    public function test_a_host_without_a_provider_api_is_not_registered_and_warns(): void
    {
        Http::fake();
        $this->project('https://git.example.test/acme/app.git', 'some-token');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration', null);
        $response->assertJsonPath('data.registration_warning', DeployHookActions::NO_REGISTRAR_WARNING);
        Http::assertNothingSent();
        $this->assertSame(1, DeployHook::count());
        $this->assertFalse(DeployHook::firstOrFail()->isRegistered());
    }

    public function test_an_existing_hook_is_registered_with_the_secret_it_already_holds(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 4], 201)]);
        $user = $this->project('https://github.com/octocat/Hello-World.git');
        $first = $this->postJson(self::URL);
        $first->assertStatus(201);
        $hook = DeployHook::firstOrFail();

        $second = $this->postJson(self::URL, ['register' => true]);

        $second->assertStatus(200);
        $second->assertJsonPath('data.registration.provider_hook_id', '4');
        $this->assertArrayNotHasKey('secret', $second->json('data'));
        Http::assertSent(fn (ClientRequest $request): bool => $request['config']['secret'] === $hook->secret()
            && $request['config']['url'] === $hook->url());
    }

    public function test_register_is_off_by_default(): void
    {
        Http::fake();
        $this->project('https://github.com/octocat/Hello-World.git');

        $response = $this->postJson(self::URL);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration', null);
        Http::assertNothingSent();
    }

    // -- rotate and destroy -------------------------------------------------

    public function test_rotate_updates_the_provider_webhook_with_the_new_url_and_secret(): void
    {
        Http::fake([
            'api.github.com/*' => Http::sequence()
                ->push(['id' => 7], 201)
                ->push([], 200),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $created = $this->postJson(self::URL, ['register' => true]);
        $created->assertStatus(201);

        $rotated = $this->postJson(self::URL . '/rotate');

        $rotated->assertStatus(200);
        $rotated->assertJsonPath('data.registration.provider_hook_id', '7');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.github.com/repos/octocat/Hello-World/hooks/7'
            && $request['config']['url'] === $rotated->json('data.url')
            && $request['config']['secret'] === $rotated->json('data.secret')
            && $request['config']['secret'] !== $created->json('data.secret'));
    }

    public function test_rotating_an_unregistered_hook_does_not_call_the_provider(): void
    {
        Http::fake();
        $this->hook($this->project('https://github.com/octocat/Hello-World.git'));

        $this->postJson(self::URL . '/rotate')->assertStatus(200);
        Http::assertNothingSent();
    }

    public function test_gitlab_rotate_and_destroy_use_put_and_delete(): void
    {
        Http::fake([
            'gitlab.com/*' => Http::sequence()
                ->push(['id' => 12], 201)
                ->push([], 200)
                ->push(null, 204),
        ]);
        $this->project('https://gitlab.com/acme/widgets.git', 'glpat-token');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $rotated = $this->postJson(self::URL . '/rotate');
        $rotated->assertStatus(200);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://gitlab.com/api/v4/projects/acme%2Fwidgets/hooks/12'
            && $request['signing_token'] === $rotated->json('data.secret')
            && $request['token'] === GitlabProvider::tokenFor((string) $rotated->json('data.secret')));

        $this->deleteJson(self::URL)->assertStatus(204);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gitlab.com/api/v4/projects/acme%2Fwidgets/hooks/12');
    }

    public function test_bitbucket_rotate_and_destroy_use_put_and_delete(): void
    {
        Http::fake([
            'api.bitbucket.org/*' => Http::sequence()
                ->push(['uuid' => '{uuid-1}'], 201)
                ->push([], 200)
                ->push(null, 204),
        ]);
        $this->project('https://bitbucket.org/acme/widgets.git', 'bb-token');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $this->postJson(self::URL . '/rotate')->assertStatus(200);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.bitbucket.org/2.0/repositories/acme/widgets/hooks/%7Buuid-1%7D');

        $this->deleteJson(self::URL)->assertStatus(204);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.bitbucket.org/2.0/repositories/acme/widgets/hooks/%7Buuid-1%7D');
    }

    public function test_a_named_provider_registers_at_a_self_managed_host(): void
    {
        Http::fake(['git.example.test/*' => Http::response(['id' => 21], 201)]);
        $this->project('https://git.example.test/inner/acme/widgets.git', 'self-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '21');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://git.example.test/api/v4/projects/inner%2Facme%2Fwidgets/hooks');
    }

    public function test_destroy_deletes_the_provider_webhook(): void
    {
        Http::fake([
            'api.github.com/*' => Http::sequence()
                ->push(['id' => 7], 201)
                ->push(null, 204),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $this->deleteJson(self::URL)->assertStatus(204);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/repos/octocat/Hello-World/hooks/7');
        $this->assertSame(0, DeployHook::count());
    }

    public function test_deleting_an_unregistered_hook_does_not_call_the_provider(): void
    {
        Http::fake();
        $this->hook($this->project('https://github.com/octocat/Hello-World.git'));

        $this->deleteJson(self::URL)->assertStatus(204);
        Http::assertNothingSent();
    }

    public function test_a_provider_that_refuses_the_delete_does_not_keep_the_engines_hook(): void
    {
        Http::fake([
            'api.github.com/*' => Http::sequence()
                ->push(['id' => 7], 201)
                ->push(['message' => 'Forbidden'], 403),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $this->deleteJson(self::URL)->assertStatus(204);
        $this->assertSame(0, DeployHook::count());
    }

    // -- command ------------------------------------------------------------

    public function test_the_command_registers_the_webhook_with_register(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 5], 201)]);
        $this->project('https://github.com/octocat/Hello-World.git');

        $exit = Artisan::call('git:deploy-hook', ['username' => 'alice', '--register' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('5', DeployHook::firstOrFail()->provider_hook_id);
    }

    public function test_the_command_fails_plainly_when_the_token_cannot_register(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Forbidden'], 403)]);
        $this->project('https://github.com/octocat/Hello-World.git');

        $exit = Artisan::call('git:deploy-hook', ['username' => 'alice', '--register' => true]);

        $this->assertSame(1, $exit);
        $output = Artisan::output();
        $this->assertStringNotContainsString('422', $output);
        $this->assertStringContainsString('refused the webhook registration', $output);
        $this->assertNull(DeployHook::firstOrFail()->provider_hook_id);
    }
}
