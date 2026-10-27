<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\GitlabProvider;
use App\Lib\DeployHook\DeployHookActions;
use App\Lib\Http\OutboundUrl;
use App\Models\DeployHook;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Registering a Deploy Hook as a webhook at the git provider the checkout came
 * from: the documented call per provider, the update on rotate, the delete on
 * destroy, and the refusals that still leave the engine's own hook in place.
 * No provider is ever called for real.
 */
class RegisterDeployHookProviderTest extends DeployHookTestCase
{
    private const URL = '/api/projects/alice/git/deploy-hook';

    /** What the provider hosts resolve to here; a host missing from the list does not resolve. */
    private const ADDRESSES = [
        'api.github.com' => ['140.82.112.5'],
        'gitlab.com' => ['172.65.251.78'],
        'api.bitbucket.org' => ['104.192.141.1'],
        'git.example.test' => ['93.184.216.20'],
        'ghe.example.test' => ['93.184.216.50'],
        'other.example.test' => ['93.184.216.30'],
        'internal.example.test' => ['10.20.30.40'],
        'rebind.example.test' => ['93.184.216.40', '169.254.169.254'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        OutboundUrl::setPolicyForTests(false, []);
        OutboundUrl::setResolverForTests(static fn (string $host): array => self::ADDRESSES[$host] ?? []);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);

        parent::tearDown();
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

    /** @return list<string> every request sent, as `METHOD url` */
    private function sentCalls(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): string => $pair[0]->method() . ' ' . $pair[0]->url())
            ->values()
            ->all();
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
        $response->assertJsonMissingPath('data.registration_warning');
    }

    public function test_a_gitlab_hook_made_before_signing_is_registered_with_a_token_only_and_told_to_rotate(): void
    {
        Http::fake(['gitlab.com/*' => Http::response(['id' => 88], 201)]);
        $legacy = 'a-shared-secret-of-some-length';
        $this->hook($this->project('https://gitlab.com/acme/widgets.git', 'glpat-token'), $legacy);

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.registration.provider_hook_id', '88');
        $response->assertJsonPath('data.registration_warning', DeployHookActions::ROTATE_TO_SIGN_WARNING);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request['token'] === GitlabProvider::tokenFor($legacy)
            && ! isset($request['signing_token']));
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
        $this->assertCarriesTheCreatedHook($response->json('problems.0.hook'));
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
        $this->assertCarriesTheCreatedHook($response->json('problems.0.hook'));
    }

    /** Only the call that made the hook may show its secret; a refused repeat shows none. */
    public function test_a_refused_registration_of_an_existing_hook_carries_no_secret(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 403)]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $this->postJson(self::URL)->assertStatus(201);

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'provider_permission_denied');
        $this->assertNull($response->json('problems.0.hook'));
        $this->assertStringNotContainsString((string) DeployHook::firstOrFail()->secret(), (string) $response->getContent());
    }

    /**
     * @param mixed $hook
     */
    private function assertCarriesTheCreatedHook($hook): void
    {
        $stored = DeployHook::firstOrFail();
        $this->assertIsArray($hook);
        $this->assertTrue($hook['created']);
        $this->assertSame($stored->url(), $hook['url']);
        $this->assertSame($stored->secret(), $hook['secret']);
        $this->assertNull($hook['registration']);
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
        $this->assertCarriesTheCreatedHook($response->json('hook'));
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

    /**
     * @return array<string, array{0: int|string}>
     */
    public static function registerSpelledAsOne(): array
    {
        return ['the number 1' => [1], 'the string "1"' => ['1']];
    }

    #[DataProvider('registerSpelledAsOne')]
    public function test_register_spelled_as_one_registers_like_true(int|string $register): void
    {
        Http::fake(['api.github.com/*' => Http::response(['id' => 4711], 201)]);
        $this->project('https://github.com/octocat/Hello-World.git');

        $response = $this->postJson(self::URL, ['register' => $register]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '4711');
        Http::assertSentCount(1);
    }

    public function test_register_spelled_as_zero_does_not_register(): void
    {
        Http::fake();
        $this->project('https://github.com/octocat/Hello-World.git');

        $response = $this->postJson(self::URL, ['register' => '0']);

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

    /**
     * A rotation retires a leaked URL and secret, so a provider that refuses to
     * follow it must not take the new secret down with it.
     */
    public function test_a_rotate_the_provider_refuses_still_rotates_and_hands_out_the_new_secret(): void
    {
        Http::fake([
            'api.github.com/*' => Http::sequence()
                ->push(['id' => 7], 201)
                ->push(['message' => 'Bad credentials'], 401),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $created = $this->postJson(self::URL, ['register' => true]);
        $created->assertStatus(201);

        $rotated = $this->postJson(self::URL . '/rotate');

        $rotated->assertStatus(200);
        $rotated->assertJsonPath('data.rotated', true);
        $rotated->assertJsonPath('data.registration.provider_hook_id', '7');
        $this->assertStringStartsWith(DeployHookActions::PROVIDER_NOT_UPDATED_WARNING, (string) $rotated->json('data.registration_warning'));
        $this->assertStringContainsString('answered 401', (string) $rotated->json('data.registration_warning'));
        $hook = DeployHook::firstOrFail();
        $this->assertNotSame($created->json('data.secret'), $rotated->json('data.secret'));
        $this->assertSame($rotated->json('data.secret'), $hook->secret());
        $this->assertSame($rotated->json('data.url'), $hook->url());
    }

    public function test_a_rotate_the_provider_cannot_answer_still_rotates(): void
    {
        Http::fake([
            'api.github.com/*' => Http::sequence()
                ->push(['id' => 7], 201)
                ->push(['message' => 'nope'], 500),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $rotated = $this->postJson(self::URL . '/rotate');

        $rotated->assertStatus(200);
        $this->assertNotEmpty($rotated->json('data.secret'));
        $rotated->assertJsonPath('data.registration.provider_hook_id', '7');
        $this->assertStringStartsWith(DeployHookActions::PROVIDER_NOT_UPDATED_WARNING, (string) $rotated->json('data.registration_warning'));
    }

    public function test_a_rotate_whose_webhook_the_provider_no_longer_has_forgets_it_so_it_can_be_registered_again(): void
    {
        Http::fake([
            'api.github.com/*' => Http::sequence()
                ->push(['id' => 7], 201)
                ->push(['message' => 'Not Found'], 404)
                ->push(['id' => 8], 201),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git');
        $this->postJson(self::URL, ['register' => true])->assertStatus(201);

        $rotated = $this->postJson(self::URL . '/rotate');
        $rotated->assertStatus(200);
        $rotated->assertJsonPath('data.registration', null);
        $this->assertFalse(DeployHook::firstOrFail()->isRegistered());

        $again = $this->postJson(self::URL . '/rotate');
        $again->assertStatus(200);
        $this->assertArrayNotHasKey('registration_warning', $again->json('data'));

        $registered = $this->postJson(self::URL, ['register' => true]);
        $registered->assertStatus(200);
        $registered->assertJsonPath('data.registration.provider_hook_id', '8');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request['config']['secret'] === $again->json('data.secret'));
        Http::assertSentCount(3);
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
        Http::fake([
            'git.example.test/api/v4/version' => Http::response(['version' => '18.4.1', 'revision' => 'abc'], 200),
            'git.example.test/*' => Http::response(['id' => 21], 201),
        ]);
        $this->project('https://git.example.test/inner/acme/widgets.git', 'self-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '21');
        $this->assertSame([
            'GET https://git.example.test/api/v4/version',
            'POST https://git.example.test/api/v4/projects/inner%2Facme%2Fwidgets/hooks',
        ], $this->sentCalls(), 'a subgroup on an instance at the root is asked once');
    }

    /**
     * `https://host/gitlab/acme/widgets` is project `widgets` of group `acme` on a GitLab
     * served under `/gitlab`, or project `widgets` of subgroup `gitlab/acme` at the root:
     * the instance is asked where its API answers, without the token.
     */
    public function test_a_self_managed_gitlab_under_a_path_is_asked_there(): void
    {
        Http::fake([
            'git.example.test/gitlab/api/v4/version' => Http::response(['message' => '401 Unauthorized'], 401),
            'git.example.test/gitlab/api/v4/projects/*' => Http::sequence()
                ->push(['id' => 31], 201)
                ->push([], 200)
                ->push(null, 204),
            'git.example.test/*' => Http::response('<html>Not Found</html>', 404),
        ]);
        $this->project('https://git.example.test/gitlab/acme/widgets.git', 'self-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab']);
        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '31');
        $this->postJson(self::URL . '/rotate')->assertStatus(200)->assertJsonMissingPath('data.registration_warning');
        $this->deleteJson(self::URL)->assertStatus(204);

        $probes = ['GET https://git.example.test/api/v4/version', 'GET https://git.example.test/gitlab/api/v4/version'];
        $this->assertSame([
            ...$probes, 'POST https://git.example.test/gitlab/api/v4/projects/acme%2Fwidgets/hooks',
            ...$probes, 'PUT https://git.example.test/gitlab/api/v4/projects/acme%2Fwidgets/hooks/31',
            ...$probes, 'DELETE https://git.example.test/gitlab/api/v4/projects/acme%2Fwidgets/hooks/31',
        ], $this->sentCalls());
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request->hasHeader('PRIVATE-TOKEN'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST' && $request->hasHeader('PRIVATE-TOKEN', 'self-token'));
    }

    public function test_a_probe_is_pinned_to_the_address_the_guard_checked_and_follows_no_redirect(): void
    {
        $sent = [];
        Http::fake(function (ClientRequest $request, array $options) use (&$sent) {
            $sent[] = [$request->method(), $options];

            return $request->method() === 'GET'
                ? Http::response(['version' => '18.4.1'], 200)
                : Http::response(['id' => 21], 201);
        });
        $this->project('https://git.example.test/inner/acme/widgets.git', 'self-token');

        $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab'])->assertStatus(201);

        $this->assertSame(['GET', 'POST'], array_column($sent, 0));
        $this->assertSame(['git.example.test:443:93.184.216.20'], $sent[0][1]['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($sent[0][1]['allow_redirects']);
    }

    public function test_a_self_managed_gitlab_whose_api_answers_nowhere_is_asked_at_the_root(): void
    {
        Http::fake([
            'git.example.test/api/v4/projects/*' => Http::response(['id' => 41], 201),
            'git.example.test/*' => Http::response('<html>Not Found</html>', 404),
        ]);
        $this->project('https://git.example.test/a/b/c.git', 'self-token');

        $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab'])->assertStatus(201);

        $this->assertSame([
            'GET https://git.example.test/api/v4/version',
            'GET https://git.example.test/a/api/v4/version',
            'POST https://git.example.test/api/v4/projects/a%2Fb%2Fc/hooks',
        ], $this->sentCalls());
    }

    public function test_a_self_managed_gitlab_that_cannot_be_reached_is_asked_once_and_is_502(): void
    {
        $calls = [];
        Http::fake(function (ClientRequest $request) use (&$calls) {
            $calls[] = $request->method() . ' ' . $request->url();
            throw new ConnectionException('Connection refused');
        });
        $this->project('https://git.example.test/a/b/c.git', 'self-token');

        $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab'])->assertStatus(502);

        $this->assertSame([
            'GET https://git.example.test/api/v4/version',
            'POST https://git.example.test/api/v4/projects/a%2Fb%2Fc/hooks',
        ], $calls);
    }

    public function test_a_self_managed_gitlab_on_its_own_port_is_asked_on_that_port(): void
    {
        Http::fake(['git.example.test:8443/*' => Http::response(['id' => 22], 201)]);
        $this->project('https://git.example.test:8443/acme/widgets.git', 'self-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '22');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://git.example.test:8443/api/v4/projects/acme%2Fwidgets/hooks');
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'GET');
    }

    public function test_a_github_enterprise_server_on_its_own_port_is_asked_on_that_port(): void
    {
        Http::fake(['ghe.example.test:8443/*' => Http::response(['id' => 23], 201)]);
        $this->project('https://ghe.example.test:8443/octo/app.git', 'ghe-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'github']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '23');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ghe.example.test:8443/api/v3/repos/octo/app/hooks');
    }

    public function test_a_github_enterprise_repository_under_a_path_is_left_to_the_client(): void
    {
        Http::fake();
        $this->project('https://example.test/github/octo/app.git', 'ghe-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'github']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration', null);
        $response->assertJsonPath('data.registration_warning', DeployHookActions::NO_REGISTRAR_WARNING);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function refusedHosts(): array
    {
        return [
            'a name resolving to a private address' => ['https://internal.example.test/acme/widgets.git'],
            'a name with one internal record among public ones' => ['https://rebind.example.test/acme/widgets.git'],
            'a name that does not resolve' => ['https://nowhere.example.test/acme/widgets.git'],
            'the metadata address itself' => ['https://169.254.169.254/acme/widgets.git'],
            'a loopback address' => ['https://127.0.0.1:8443/acme/widgets.git'],
            'a private address, never probed for an instance path' => ['https://internal.example.test/gitlab/acme/widgets.git'],
            'the metadata address, never probed for an instance path' => ['https://169.254.169.254/gitlab/acme/widgets.git'],
        ];
    }

    #[DataProvider('refusedHosts')]
    public function test_a_named_provider_at_a_refused_host_is_never_called_and_answers_alike(string $repoUrl): void
    {
        Http::fake();
        $this->project($repoUrl, 'self-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'registration');
        $response->assertJsonPath('problems.0.code', 'host_refused');
        $response->assertJsonPath('problems.0.message', $this->refusedMessage('gitlab'));
        Http::assertNothingSent();
        $this->assertFalse(DeployHook::firstOrFail()->isRegistered());
    }

    public function test_every_call_is_pinned_to_the_address_the_guard_checked(): void
    {
        $sent = [];
        Http::fake(function (ClientRequest $request, array $options) use (&$sent) {
            $sent[] = $options;

            return Http::response(['id' => 21], 201);
        });
        $this->project('https://git.example.test/acme/widgets.git', 'self-token');

        $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab'])->assertStatus(201);

        $this->assertCount(1, $sent);
        $this->assertSame(['git.example.test:443:93.184.216.20'], $sent[0]['curl'][CURLOPT_RESOLVE]);
        $this->assertIsCallable($sent[0]['allow_redirects']['on_redirect']);
    }

    /** @return array<string, array{string}> */
    public static function otherHostRedirects(): array
    {
        return [
            'an internal address' => ['http://10.0.0.5:8080/api/v4/projects/acme%2Fwidgets/hooks'],
            'another public host' => ['https://other.example.test/api/v4/projects/acme%2Fwidgets/hooks'],
            'the same host over http' => ['http://git.example.test/api/v4/projects/acme%2Fwidgets/hooks'],
            'the same host on another port' => ['https://git.example.test:8443/api/v4/projects/acme%2Fwidgets/hooks'],
        ];
    }

    #[DataProvider('otherHostRedirects')]
    public function test_a_redirect_away_from_the_repository_host_is_not_followed(string $location): void
    {
        Http::fake([
            'git.example.test/api/v4/projects/acme%2Fwidgets/hooks' => Http::response('', 307, ['Location' => $location]),
            '*' => Http::response(['id' => 666], 201),
        ]);
        $this->project('https://git.example.test/acme/widgets.git', 'self-token');

        $response = $this->postJson(self::URL, ['register' => true, 'provider' => 'gitlab']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'host_refused');
        $response->assertJsonPath('problems.0.message', $this->refusedMessage('gitlab'));
        Http::assertSentCount(1);
        $this->assertFalse(DeployHook::firstOrFail()->isRegistered());
    }

    public function test_a_redirect_on_the_same_host_is_followed(): void
    {
        Http::fake([
            'api.github.com/repos/octocat/Hello-World/hooks' => Http::response('', 307, ['Location' => 'https://api.github.com/repositories/1296269/hooks']),
            'api.github.com/repositories/1296269/hooks' => Http::response(['id' => 31], 201),
        ]);
        $this->project('https://github.com/octocat/Hello-World.git', 'ghp-token');

        $response = $this->postJson(self::URL, ['register' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.registration.provider_hook_id', '31');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repositories/1296269/hooks'
            && $request->hasHeader('Authorization', 'Bearer ghp-token'));
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

    private function refusedMessage(string $provider): string
    {
        return "The engine did not call {$provider}: it calls a provider API only at a public address of the "
            . "repository's own host, and never follows a redirect to another host. "
            . 'Register the hook URL in the git host yourself.';
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
        $this->assertDoesNotMatchRegularExpression('/\b422\b/', $output);
        $this->assertStringContainsString('refused the webhook registration', $output);
        $this->assertNull(DeployHook::firstOrFail()->provider_hook_id);
        $this->assertStringContainsString((string) DeployHook::firstOrFail()->secret(), $output);
    }
}
