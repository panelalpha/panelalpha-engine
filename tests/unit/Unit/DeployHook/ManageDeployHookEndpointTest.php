<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\DeployHooks;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use Illuminate\Support\Facades\Queue;

/**
 * Show, rotate and delete over REST -- the rest of the management surface the
 * create endpoint started -- through the router, with only bearer
 * authentication switched off.
 */
class ManageDeployHookEndpointTest extends DeployHookTestCase
{
    private const URL = '/api/projects/alice/git/deploy-hook';
    private const SECRET = 'a-shared-secret-of-some-length';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Queue::fake();
    }

    /**
     * @return array<string, string>
     */
    private function githubHeaders(string $body, string $secret = self::SECRET, string $delivery = 'guid-manage-1'): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
        ];
    }

    private function deliverTo(string $publicId, string $secret = self::SECRET, string $delivery = 'guid-manage-1'): int
    {
        $body = '{"zen":"Keep it logically awesome."}';

        return $this->call('POST', '/hooks/' . $publicId, [], [], [], $this->githubHeaders($body, $secret, $delivery), $body)->getStatusCode();
    }

    // -- show ---------------------------------------------------------------

    public function test_show_returns_the_url_and_never_the_secret(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);

        $response = $this->getJson(self::URL);

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', $hook->url());
        $response->assertJsonPath('data.path', 'project');
        $response->assertJsonPath('data.warning', DeployHooks::FORCE_PULL_WARNING);
        $this->assertArrayHasKey('created_at', $response->json('data'));
        $this->assertArrayNotHasKey('secret', $response->json('data'));
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $this->assertStringNotContainsString($hook->secret_encrypted, (string) $response->getContent());
    }

    public function test_show_and_rotate_do_not_warn_about_a_forced_pull_for_a_site_git_checkout(): void
    {
        $this->hook($this->siteGitUser(), self::SECRET, 'public_html');

        $shown = $this->getJson(self::URL . '?path=public_html');
        $rotated = $this->postJson(self::URL . '/rotate', ['path' => 'public_html']);

        $shown->assertStatus(200);
        $this->assertArrayNotHasKey('warning', $shown->json('data'));
        $rotated->assertStatus(200);
        $this->assertArrayNotHasKey('warning', $rotated->json('data'));
        $this->assertNotSame('', (string) $rotated->json('data.secret'));
    }

    public function test_show_reports_no_address_change_right_after_creation(): void
    {
        $this->user('main');
        $this->postJson(self::URL, [])->assertStatus(201);

        $response = $this->getJson(self::URL);

        $response->assertJsonPath('data.url_changed_since_registration', false);
        $this->assertSame($response->json('data.url'), $response->json('data.registered_url'));
    }

    public function test_show_reports_the_address_changed_once_the_engines_url_moves_on(): void
    {
        $this->user('main');
        $created = $this->postJson(self::URL, []);
        $created->assertStatus(201);

        config(['app.url' => 'https://deploys.example.test']);

        $response = $this->getJson(self::URL);

        $response->assertJsonPath('data.url_changed_since_registration', true);
        $this->assertSame($created->json('data.url'), $response->json('data.registered_url'));
        $this->assertNotSame($response->json('data.registered_url'), $response->json('data.url'));
    }

    public function test_show_lists_recent_deliveries_newest_first(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $this->deliverTo($hook->public_id, delivery: 'guid-list-1');
        $this->deliverTo($hook->public_id, delivery: 'guid-list-2');

        $deliveries = $this->getJson(self::URL)->json('data.deliveries');

        $this->assertCount(2, $deliveries);
        $this->assertSame('guid-list-2', HookDelivery::orderByDesc('id')->first()->delivery_id);
        $this->assertSame('github', $deliveries[0]['provider']);
        $this->assertSame('ignored', $deliveries[0]['outcome']);
    }

    public function test_show_reports_the_tls_state(): void
    {
        $this->hook($this->user('main'), self::SECRET);

        $tls = $this->getJson(self::URL)->json('data.tls');

        $this->assertArrayHasKey('state', $tls);
        $this->assertArrayHasKey('warning', $tls);
    }

    public function test_show_is_404_when_the_checkout_has_no_hook(): void
    {
        $this->user('main');

        $this->getJson(self::URL)->assertStatus(404);
    }

    public function test_show_for_an_unknown_project_is_404(): void
    {
        $this->getJson('/api/projects/nobody/git/deploy-hook')->assertStatus(404);
    }

    public function test_show_does_not_reveal_another_projects_hook(): void
    {
        $this->user('main');
        $this->hook($this->user('main', username: 'bob'));

        $this->getJson(self::URL)->assertStatus(404);
    }

    // -- rotate -------------------------------------------------------------

    public function test_rotate_returns_a_new_url_and_a_new_secret(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $oldUrl = $hook->url();

        $response = $this->postJson(self::URL . '/rotate');

        $response->assertStatus(200);
        $response->assertJsonPath('data.path', 'project');
        $response->assertJsonPath('data.warning', DeployHooks::FORCE_PULL_WARNING);
        $this->assertNotSame($oldUrl, $response->json('data.url'));
        $this->assertMatchesRegularExpression('#^https://203\.0\.113\.10/hooks/[0-9a-f]{40}$#', (string) $response->json('data.url'));
        $secret = (string) $response->json('data.secret');
        $this->assertNotSame('', $secret);
        $this->assertNotSame(self::SECRET, $secret);

        $this->assertSame(1, DeployHook::count());
        $stored = DeployHook::firstOrFail();
        $this->assertSame($response->json('data.url'), $stored->url());
        $this->assertSame($secret, $stored->secret());
    }

    public function test_after_a_rotation_a_delivery_to_the_old_url_is_404(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $oldId = $hook->public_id;
        $this->assertSame(200, $this->deliverTo($oldId));

        $this->postJson(self::URL . '/rotate')->assertStatus(200);

        $this->assertSame(404, $this->deliverTo($oldId, delivery: 'guid-manage-2'));
    }

    public function test_the_new_url_answers_and_is_signed_with_the_new_secret_only(): void
    {
        $this->hook($this->user('main'), self::SECRET);
        $rotated = $this->postJson(self::URL . '/rotate');
        $newId = basename((string) $rotated->json('data.url'));

        // The old secret no longer verifies on the new address ...
        $this->assertSame(401, $this->deliverTo($newId, self::SECRET));

        // ... and the new one does.
        $this->assertSame(200, $this->deliverTo($newId, (string) $rotated->json('data.secret'), 'guid-manage-3'));
    }

    public function test_rotate_keeps_the_delivery_history(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $this->deliverTo($hook->public_id);
        $this->assertSame(1, HookDelivery::count());

        $this->postJson(self::URL . '/rotate')->assertStatus(200);

        $this->assertSame(1, HookDelivery::count());
    }

    public function test_a_hook_that_outlived_its_checkout_can_still_be_shown_rotated_and_deleted(): void
    {
        // Disconnected from git: create would now refuse (422), but the hook
        // exists, and a client must be able to see it and get rid of it.
        $hook = $this->hook($this->user('main', ['git_repo' => '']), self::SECRET);

        $this->getJson(self::URL)->assertStatus(200)->assertJsonPath('data.url', $hook->url());
        $this->postJson(self::URL . '/rotate')->assertStatus(200);
        $this->deleteJson(self::URL)->assertStatus(204);
        $this->assertSame(0, DeployHook::count());
    }

    public function test_rotate_is_404_when_there_is_no_hook_and_creates_nothing(): void
    {
        $this->user('main');

        $this->postJson(self::URL . '/rotate')->assertStatus(404);
        $this->assertSame(0, DeployHook::count());
    }

    // -- delete -------------------------------------------------------------

    public function test_delete_is_204_and_the_url_stops_answering(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $this->assertSame(200, $this->deliverTo($hook->public_id));

        $response = $this->deleteJson(self::URL);

        $response->assertStatus(204);
        $this->assertSame('', (string) $response->getContent());
        $this->assertSame(0, DeployHook::count());
        $this->assertSame(404, $this->deliverTo($hook->public_id, delivery: 'guid-manage-2'));
    }

    public function test_delete_removes_the_hooks_deliveries(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $this->deliverTo($hook->public_id);
        $this->assertSame(1, HookDelivery::count());

        $this->deleteJson(self::URL)->assertStatus(204);

        $this->assertSame(0, HookDelivery::count());
    }

    public function test_delete_is_404_when_there_is_no_hook(): void
    {
        $this->user('main');

        $this->deleteJson(self::URL)->assertStatus(404);
    }

    public function test_a_deleted_hook_can_be_created_again_with_a_new_secret(): void
    {
        $hook = $this->hook($this->user('main'), self::SECRET);
        $this->deleteJson(self::URL)->assertStatus(204);

        $response = $this->postJson(self::URL);

        $response->assertStatus(201);
        $this->assertNotSame($hook->url(), $response->json('data.url'));
        $this->assertNotSame('', (string) $response->json('data.secret'));
    }

    // -- one hook per checkout ----------------------------------------------

    public function test_a_project_holds_one_hook_per_checkout_selected_by_path(): void
    {
        $user = $this->user('main');
        $managed = $this->hook($user, 'secret-of-the-managed-checkout');
        $site = $this->hook($user, 'secret-of-the-site-checkout', 'wp-content/themes/mine');

        $shown = $this->getJson(self::URL . '?path=wp-content/themes/mine');
        $shown->assertStatus(200);
        $shown->assertJsonPath('data.path', 'wp-content/themes/mine');
        $shown->assertJsonPath('data.url', $site->url());

        // The default is still the Deploy-managed checkout.
        $this->getJson(self::URL)->assertJsonPath('data.url', $managed->url());
    }

    public function test_rotating_one_checkouts_hook_leaves_the_other_alone(): void
    {
        $user = $this->user('main');
        $managed = $this->hook($user, 'secret-of-the-managed-checkout');
        $site = $this->hook($user, 'secret-of-the-site-checkout', 'wp-content/themes/mine');

        $this->postJson(self::URL . '/rotate?path=wp-content/themes/mine')->assertStatus(200);

        $this->assertSame($managed->public_id, $managed->fresh()->public_id);
        $this->assertSame('secret-of-the-managed-checkout', $managed->fresh()->secret());
        $this->assertNotSame($site->public_id, $site->fresh()->public_id);
    }

    public function test_deleting_one_checkouts_hook_leaves_the_other_alone(): void
    {
        $user = $this->user('main');
        $managed = $this->hook($user, 'secret-of-the-managed-checkout');
        $this->hook($user, 'secret-of-the-site-checkout', 'wp-content/themes/mine');

        $this->deleteJson(self::URL . '?path=wp-content/themes/mine')->assertStatus(204);

        $this->assertSame([$managed->id], DeployHook::pluck('id')->all());
    }

    // -- both prefixes ------------------------------------------------------

    public function test_the_legacy_users_prefix_reaches_all_three(): void
    {
        $this->hook($this->user('main'), self::SECRET);

        $this->getJson('/api/users/alice/git/deploy-hook')->assertStatus(200);
        $this->postJson('/api/users/alice/git/deploy-hook/rotate')->assertStatus(200);
        $this->deleteJson('/api/users/alice/git/deploy-hook')->assertStatus(204);
    }
}
