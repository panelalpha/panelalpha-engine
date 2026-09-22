<?php

namespace Tests\Unit\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Models\HookDelivery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * The public endpoint, through the router: that it answers with no bearer
 * token, that the signature is checked against the bytes the host sent, and
 * that an address nobody was given is a plain 404.
 */
class HookEndpointTest extends DeployHookTestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/github-hooks/' . $name);
    }

    /**
     * @return array<string, string>
     */
    private function githubHeaders(string $event, string $body, string $delivery = 'guid-http-1', string $contentType = 'application/json'): array
    {
        return [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET),
        ];
    }

    public function test_a_signed_push_is_202_with_no_bearer_token(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = $this->fixture('push-main.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], $this->githubHeaders('push', $body), $body);

        $response->assertStatus(202);
        $response->assertJson(['outcome' => 'queued']);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_form_encoded_push_signed_over_the_encoded_body_is_accepted(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = 'payload=' . rawurlencode($this->fixture('push-main.json'));

        $response = $this->call(
            'POST',
            '/hooks/' . $hook->public_id,
            [],
            [],
            [],
            $this->githubHeaders('push', $body, 'guid-http-form', 'application/x-www-form-urlencoded'),
            $body,
        );

        $response->assertStatus(202);
        $this->assertSame(1, HookDelivery::where('outcome', HookDelivery::OUTCOME_QUEUED)->count());
    }

    public function test_a_ping_is_200(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = $this->fixture('ping.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], $this->githubHeaders('ping', $body), $body);

        $response->assertStatus(200);
    }

    public function test_a_gitlab_push_with_the_secret_token_is_202_with_no_bearer_token(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = (string) file_get_contents(__DIR__ . '/../../fixtures/gitlab-hooks/push-main.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'Push Hook',
            'HTTP_X_GITLAB_TOKEN' => self::SECRET,
            'HTTP_IDEMPOTENCY_KEY' => 'gl-http-1',
        ], $body);

        $response->assertStatus(202);
        $response->assertJson(['outcome' => 'queued']);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_gitlab_push_with_the_wrong_token_is_401(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = (string) file_get_contents(__DIR__ . '/../../fixtures/gitlab-hooks/push-main.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'Push Hook',
            'HTTP_X_GITLAB_TOKEN' => 'nope',
        ], $body);

        $response->assertStatus(401);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, HookDelivery::firstOrFail()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_cloud_push_is_202_with_no_bearer_token(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = (string) file_get_contents(__DIR__ . '/../../fixtures/bitbucket-cloud-hooks/push-main.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => 'repo:push',
            'HTTP_X_HOOK_UUID' => '{hook-http}',
            'HTTP_X_REQUEST_UUID' => 'cloud-http-1',
            'HTTP_X_HUB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET),
        ], $body);

        $response->assertStatus(202);
        $response->assertJson(['outcome' => 'queued']);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_bitbucket_data_center_ping_is_200_and_a_bad_signature_is_401(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $body = (string) file_get_contents(__DIR__ . '/../../fixtures/bitbucket-dc-hooks/ping.json');
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => 'diagnostics:ping',
            'HTTP_X_REQUEST_ID' => 'dc-http-1',
        ];

        $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], $headers + [
            'HTTP_X_HUB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertStatus(200);

        $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], $headers + [
            'HTTP_X_HUB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, 'not-the-secret'),
        ], $body)->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_id_is_404_and_leaves_no_trace(): void
    {
        $this->hook($this->user('main'), self::SECRET);
        $body = $this->fixture('push-main.json');

        $response = $this->call('POST', '/hooks/' . str_repeat('a', 40), [], [], [], $this->githubHeaders('push', $body), $body);

        $response->assertStatus(404);
        $this->assertSame(0, HookDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_a_hook_whose_project_is_gone_is_404(): void
    {
        $user = $this->user('main');
        $hook = $this->hook($user, self::SECRET);
        // Not $user->delete(): that runs the model's cleanup across tables this test does not have.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->delete();
        $body = $this->fixture('push-main.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], $this->githubHeaders('push', $body), $body);

        $response->assertStatus(404);
        $this->assertSame(0, HookDelivery::count());
    }

    public function test_a_bad_signature_is_401(): void
    {
        $hook = $this->hook($this->user('main'), 'a-different-secret');
        $body = $this->fixture('push-main.json');

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], $this->githubHeaders('push', $body), $body);

        $response->assertStatus(401);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, HookDelivery::firstOrFail()->outcome);
    }

    public function test_a_request_without_any_providers_headers_is_400(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);

        $response = $this->call('POST', '/hooks/' . $hook->public_id, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $response->assertStatus(400);
        $this->assertSame('unsupported provider', HookDelivery::firstOrFail()->reason);
    }

    public function test_the_route_is_outside_the_authenticated_and_cookie_middleware_groups(): void
    {
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/hooks/' . str_repeat('a', 40), 'POST'));

        // No `api` (bearer auth), no `web` (session, cookies, CSRF): a git
        // host's POST can satisfy none of them.
        $this->assertSame([], $route->gatherMiddleware());
    }
}
