<?php

namespace Tests\Unit\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Lib\DeployHook\HookReceiver;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/**
 * What a delivery is answered and what is written down, for every way one can
 * arrive. The bodies are the GitHub- and GitLab-shaped payloads in
 * tests/fixtures; GitHub's signatures are computed here with the hook's secret
 * and GitLab's token is that secret.
 */
class HookReceiverTest extends DeployHookTestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    private DeployHook $hook;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->hook = $this->hook($this->user('main'), self::SECRET);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/github-hooks/' . $name);
    }

    private function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $body, array $server): Request
    {
        return Request::create('/hooks/' . $this->hook->public_id, 'POST', [], [], [], $server, $body);
    }

    private function github(string $event, string $fixture, string $delivery = 'guid-1', ?string $secret = self::SECRET): Request
    {
        $body = $this->fixture($fixture);

        return $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body, $secret ?? self::SECRET),
        ]);
    }

    /**
     * A GitLab delivery. `$key` is the Idempotency-Key GitLab repeats on a
     * retry; `$uuid` the event UUID, which a single request's pushes share.
     */
    private function gitlab(string $fixture, string $event = 'Push Hook', string $key = 'gl-key-1', ?string $token = self::SECRET, string $uuid = 'gl-uuid-1'): Request
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => $event,
            'HTTP_X_GITLAB_EVENT_UUID' => $uuid,
        ];
        if ($key !== '') {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        if ($token !== null) {
            $server['HTTP_X_GITLAB_TOKEN'] = $token;
        }

        return $this->request((string) file_get_contents(__DIR__ . '/../../fixtures/gitlab-hooks/' . $fixture), $server);
    }

    private function receive(Request $request): \App\Lib\DeployHook\HookResponse
    {
        return (new HookReceiver())->receive($this->hook, $request);
    }

    private function onlyDelivery(): HookDelivery
    {
        $this->assertSame(1, HookDelivery::count(), 'expected exactly one recorded delivery');

        return HookDelivery::firstOrFail();
    }

    public function test_a_ping_is_200_and_recorded_as_ignored(): void
    {
        $response = $this->receive($this->github('ping', 'ping.json'));

        $this->assertSame(200, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertSame('ping', $delivery->reason);
        $this->assertSame('ping', $delivery->event);
        $this->assertNull($delivery->result);
        Queue::assertNothingPushed();
    }

    public function test_a_push_to_the_tracked_branch_is_202_and_queued(): void
    {
        $response = $this->receive($this->github('push', 'push-main.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertNull($delivery->result, 'the result is for the work to fill in, not the request');
        $this->assertSame('github', $delivery->provider);
        $this->assertSame('guid-1', $delivery->delivery_id);
        $this->assertSame('push', $delivery->event);
        $this->assertSame('main', $delivery->branch);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $delivery->commit);
        $this->assertSame($this->hook->id, $delivery->deploy_hook_id);
        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $delivery->id);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_site_git_hook_follows_the_branch_its_checkout_tracks(): void
    {
        $this->hook = $this->hook($this->siteGitUser('main', 'bob'), self::SECRET, 'public_html');

        $this->receive($this->github('push', 'push-main.json', 'guid-site-1'));

        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertSame($this->hook->id, $delivery->deploy_hook_id);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_push_to_a_branch_a_site_git_checkout_does_not_track_is_ignored(): void
    {
        $this->hook = $this->hook($this->siteGitUser('develop', 'bob'), self::SECRET, 'public_html');

        $this->receive($this->github('push', 'push-main.json', 'guid-site-2'));

        $delivery = HookDelivery::query()->where('deploy_hook_id', $this->hook->id)->firstOrFail();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('develop', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_form_encoded_push_is_accepted_the_same_way(): void
    {
        $body = 'payload=' . rawurlencode($this->fixture('push-main.json'));
        $request = $this->request($body, [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'guid-form',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);

        $response = $this->receive($request);

        $this->assertSame(202, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $this->onlyDelivery()->outcome);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_push_to_another_branch_is_202_and_ignored_with_the_reason(): void
    {
        $response = $this->receive($this->github('push', 'push-other-branch.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('feature/login', (string) $delivery->reason);
        $this->assertStringContainsString('main', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_tag_push_is_202_and_ignored(): void
    {
        $response = $this->receive($this->github('push', 'push-tag.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('tag', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_deleting_the_tracked_branch_is_202_and_ignored(): void
    {
        $response = $this->receive($this->github('push', 'push-branch-deleted.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('deleted', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_an_event_the_engine_does_not_act_on_is_202_and_ignored(): void
    {
        $body = '{"action":"opened"}';
        $request = $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'issues',
            'HTTP_X_GITHUB_DELIVERY' => 'guid-issues',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);

        $response = $this->receive($request);

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('issues', (string) $delivery->reason);
    }

    /**
     * The branch belongs to the checkout, not to the hook: after a branch
     * change (`Git::changeBranch()` persists the new branch the same way this
     * does), a push to the newly tracked branch deploys and one to the old
     * branch is ignored -- with no need to touch the hook itself.
     */
    public function test_after_a_branch_change_a_push_follows_the_new_branch_and_ignores_the_old_one(): void
    {
        $this->hook->user->putSiteGit('project', [
            'repo_url' => 'https://github.com/octocat/Hello-World.git',
            'branch' => 'feature/login',
            'token' => null,
        ]);

        $toOld = $this->receive($this->github('push', 'push-main.json', 'guid-old-branch'));
        $toNew = $this->receive($this->github('push', 'push-other-branch.json', 'guid-new-branch'));

        $this->assertSame(HookDelivery::OUTCOME_IGNORED, HookDelivery::where('delivery_id', 'guid-old-branch')->firstOrFail()->outcome);
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, HookDelivery::where('delivery_id', 'guid-new-branch')->firstOrFail()->outcome);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_push_when_the_project_has_no_tracked_branch_is_ignored(): void
    {
        $this->hook->user->update(['details' => ['template' => 'dind']]);

        $response = $this->receive($this->github('push', 'push-main.json'));

        $this->assertSame(202, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_bad_signature_is_401_and_recorded_as_rejected(): void
    {
        $response = $this->receive($this->github('push', 'push-main.json', secret: 'not-the-secret'));

        $this->assertSame(401, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $delivery->outcome);
        $this->assertStringContainsString('signature', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_missing_signature_is_401_and_recorded_as_rejected(): void
    {
        $request = $this->request($this->fixture('push-main.json'), [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'guid-unsigned',
        ]);

        $response = $this->receive($request);

        $this->assertSame(401, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_request_from_no_supported_provider_is_400_and_recorded_as_rejected(): void
    {
        $request = $this->request('{"ref":"refs/heads/main"}', ['CONTENT_TYPE' => 'application/json']);

        $response = $this->receive($request);

        $this->assertSame(400, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $delivery->outcome);
        $this->assertSame('unsupported provider', $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_redelivery_is_202_and_records_and_deploys_nothing_more(): void
    {
        $first = $this->receive($this->github('push', 'push-main.json', 'guid-same'));
        $again = $this->receive($this->github('push', 'push-main.json', 'guid-same'));

        $this->assertSame(202, $first->status);
        $this->assertSame(202, $again->status);
        $this->assertSame(1, HookDelivery::count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_redelivered_ping_is_not_recorded_twice_either(): void
    {
        $this->receive($this->github('ping', 'ping.json', 'guid-ping'));
        $again = $this->receive($this->github('ping', 'ping.json', 'guid-ping'));

        $this->assertSame(200, $again->status);
        $this->assertSame(1, HookDelivery::count());
    }

    public function test_a_forged_request_cannot_claim_a_delivery_id(): void
    {
        // Someone who saw a delivery id in a log sends a forgery under it
        // first. The real delivery, arriving after, must still be acted on.
        $this->receive($this->github('push', 'push-main.json', 'guid-claimed', secret: 'forged'));
        $real = $this->receive($this->github('push', 'push-main.json', 'guid-claimed'));

        $this->assertSame(202, $real->status);
        $this->assertSame(1, HookDelivery::where('outcome', HookDelivery::OUTCOME_QUEUED)->count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_valid_signature_over_a_body_that_is_not_a_push_is_400_and_rejected(): void
    {
        $body = 'this is not json';
        $request = $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'guid-garbage',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);

        $response = $this->receive($request);

        $this->assertSame(400, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_gitlab_push_to_the_tracked_branch_is_202_and_queued(): void
    {
        $response = $this->receive($this->gitlab('push-main.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertNull($delivery->result);
        $this->assertSame('gitlab', $delivery->provider);
        $this->assertSame('gl-key-1', $delivery->delivery_id);
        $this->assertSame('Push Hook', $delivery->event);
        $this->assertSame('main', $delivery->branch);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $delivery->commit);
        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $delivery->id);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_gitlab_push_to_another_branch_is_202_and_ignored_with_the_reason(): void
    {
        $response = $this->receive($this->gitlab('push-other-branch.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('feature/login', (string) $delivery->reason);
        $this->assertStringContainsString('main', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_gitlab_tag_push_is_202_and_ignored(): void
    {
        $response = $this->receive($this->gitlab('push-tag.json', 'Tag Push Hook'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertSame('tag push', $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_deleting_the_tracked_branch_on_gitlab_is_202_and_ignored(): void
    {
        $response = $this->receive($this->gitlab('push-branch-deleted.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('deleted', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_gitlab_event_the_engine_does_not_act_on_is_202_and_ignored(): void
    {
        $request = $this->request('{"object_kind":"merge_request"}', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'Merge Request Hook',
            'HTTP_X_GITLAB_TOKEN' => self::SECRET,
            'HTTP_IDEMPOTENCY_KEY' => 'gl-key-mr',
        ]);

        $response = $this->receive($request);

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('Merge Request Hook', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_wrong_gitlab_token_is_401_and_recorded_as_rejected(): void
    {
        $response = $this->receive($this->gitlab('push-main.json', token: 'not-the-secret'));

        $this->assertSame(401, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $delivery->outcome);
        $this->assertSame('gitlab', $delivery->provider);
        $this->assertNull($delivery->delivery_id, 'an unverified sender does not get to write a delivery id');
        Queue::assertNothingPushed();
    }

    public function test_a_missing_gitlab_token_is_401_and_recorded_as_rejected(): void
    {
        $response = $this->receive($this->gitlab('push-main.json', token: null));

        $this->assertSame(401, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_gitlab_redelivery_is_202_and_records_and_deploys_nothing_more(): void
    {
        $first = $this->receive($this->gitlab('push-main.json', key: 'gl-key-same'));
        $again = $this->receive($this->gitlab('push-main.json', key: 'gl-key-same'));

        $this->assertSame(202, $first->status);
        $this->assertSame(202, $again->status);
        $this->assertSame(1, HookDelivery::count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_gitlab_redelivery_without_an_idempotency_key_is_still_recognised_by_its_event_uuid(): void
    {
        $this->receive($this->gitlab('push-main.json', key: '', uuid: 'gl-uuid-retry'));
        $again = $this->receive($this->gitlab('push-main.json', key: '', uuid: 'gl-uuid-retry'));

        $this->assertSame(202, $again->status);
        $this->assertSame(1, HookDelivery::count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_two_pushes_of_one_git_push_share_an_event_uuid_and_both_are_acted_on(): void
    {
        // `git push origin feature/login main`: one request, one event UUID,
        // two push events. The tracked branch must still deploy.
        $this->receive($this->gitlab('push-other-branch.json', key: '', uuid: 'gl-uuid-shared'));
        $this->receive($this->gitlab('push-main.json', key: '', uuid: 'gl-uuid-shared'));

        $this->assertSame(2, HookDelivery::count());
        $this->assertSame(1, HookDelivery::where('outcome', HookDelivery::OUTCOME_QUEUED)->count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_forged_gitlab_request_cannot_claim_a_delivery_id(): void
    {
        $this->receive($this->gitlab('push-main.json', key: 'gl-key-claimed', token: 'forged'));
        $real = $this->receive($this->gitlab('push-main.json', key: 'gl-key-claimed'));

        $this->assertSame(202, $real->status);
        $this->assertSame(1, HookDelivery::where('outcome', HookDelivery::OUTCOME_QUEUED)->count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_gitlab_token_body_that_is_not_a_push_is_400_and_rejected(): void
    {
        $request = $this->request('this is not json', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'Push Hook',
            'HTTP_X_GITLAB_TOKEN' => self::SECRET,
        ]);

        $response = $this->receive($request);

        $this->assertSame(400, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    /**
     * A Bitbucket Cloud `repo:push`, signed with `X-Hub-Signature`. `$uuid` is
     * the X-Request-UUID a retry repeats.
     */
    private function cloud(string $fixture, string $uuid = 'cloud-req-1', ?string $secret = self::SECRET, string $event = 'repo:push'): Request
    {
        $body = (string) file_get_contents(__DIR__ . '/../../fixtures/bitbucket-cloud-hooks/' . $fixture);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => $event,
            'HTTP_X_HOOK_UUID' => '{hook-uuid}',
            'HTTP_X_REQUEST_UUID' => $uuid,
            'HTTP_X_ATTEMPT_NUMBER' => '1',
        ];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE'] = $this->sign($body, $secret);
        }

        return $this->request($body, $server);
    }

    /** A Bitbucket Data Center delivery, signed the same way; `$id` is X-Request-Id. */
    private function dataCenter(string $fixture, string $id = 'dc-req-1', ?string $secret = self::SECRET, string $event = 'repo:refs_changed'): Request
    {
        $body = (string) file_get_contents(__DIR__ . '/../../fixtures/bitbucket-dc-hooks/' . $fixture);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => $event,
            'HTTP_X_REQUEST_ID' => $id,
        ];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE'] = $this->sign($body, $secret);
        }

        return $this->request($body, $server);
    }

    public function test_a_bitbucket_cloud_push_to_the_tracked_branch_is_202_and_queued(): void
    {
        $response = $this->receive($this->cloud('push-main.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertSame('bitbucket-cloud', $delivery->provider);
        $this->assertSame('cloud-req-1', $delivery->delivery_id);
        $this->assertSame('repo:push', $delivery->event);
        $this->assertSame('main', $delivery->branch);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $delivery->commit);
        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $delivery->id);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_bitbucket_cloud_ignores_a_deletion_a_tag_and_another_branch_with_a_reason(): void
    {
        foreach ([
            'push-branch-deleted.json' => 'deleted',
            'push-tag.json' => 'tag push',
            'push-other-branch.json' => 'feature/login',
        ] as $fixture => $reason) {
            $response = $this->receive($this->cloud($fixture, uuid: 'cloud-' . $fixture));

            $this->assertSame(202, $response->status, $fixture);
            $delivery = HookDelivery::where('delivery_id', 'cloud-' . $fixture)->firstOrFail();
            $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome, $fixture);
            $this->assertStringContainsString($reason, (string) $delivery->reason, $fixture);
        }

        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_cloud_push_of_several_refs_deploys_once_and_records_the_tracked_one(): void
    {
        $response = $this->receive($this->cloud('push-multi-change.json'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertSame('main', $delivery->branch, 'the row names the change that deploys, not the first one listed');
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $delivery->commit);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_bitbucket_cloud_push_of_several_refs_none_of_them_tracked_is_ignored(): void
    {
        $this->hook->user->update(['details' => array_merge($this->hook->user->details, ['git_branch' => 'release'])]);

        $this->receive($this->cloud('push-multi-change.json'));

        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('release', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_cloud_push_with_no_changes_is_ignored(): void
    {
        $request = $this->request('{"push":{"changes":[]}}', [
            'HTTP_X_EVENT_KEY' => 'repo:push',
            'HTTP_X_REQUEST_UUID' => 'cloud-empty',
            'HTTP_X_HUB_SIGNATURE' => $this->sign('{"push":{"changes":[]}}'),
        ]);

        $response = $this->receive($request);

        $this->assertSame(202, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_bad_or_missing_bitbucket_cloud_signature_is_401_and_rejected(): void
    {
        foreach ([['forged', 'wrong-secret'], ['missing', null]] as [$uuid, $secret]) {
            $response = $this->receive($this->cloud('push-main.json', $uuid, $secret));

            $this->assertSame(401, $response->status, $uuid);
        }

        $this->assertSame(2, HookDelivery::where('outcome', HookDelivery::OUTCOME_REJECTED)->count());
        $this->assertSame(0, HookDelivery::whereNotNull('delivery_id')->count(), 'an unverified sender does not get to write a delivery id');
        $this->assertSame('bitbucket-cloud', HookDelivery::firstOrFail()->provider);
        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_cloud_retry_is_202_and_deploys_nothing_more(): void
    {
        $first = $this->receive($this->cloud('push-main.json', 'cloud-same'));
        $again = $this->receive($this->cloud('push-main.json', 'cloud-same'));

        $this->assertSame(202, $first->status);
        $this->assertSame(202, $again->status);
        $this->assertSame(1, HookDelivery::count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_bitbucket_data_center_update_or_add_of_the_tracked_branch_is_202_and_queued(): void
    {
        foreach (['push-main.json' => 'dc-update', 'push-branch-added.json' => 'dc-add'] as $fixture => $id) {
            $response = $this->receive($this->dataCenter($fixture, $id));

            $this->assertSame(202, $response->status, $fixture);
            $delivery = HookDelivery::where('delivery_id', $id)->firstOrFail();
            $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome, $fixture);
            $this->assertSame('bitbucket-data-center', $delivery->provider);
            $this->assertSame('repo:refs_changed', $delivery->event);
            $this->assertSame('main', $delivery->branch);
            $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $delivery->commit);
        }

        Queue::assertPushed(RunHookDelivery::class, 2);
    }

    public function test_bitbucket_data_center_ignores_a_delete_a_tag_and_another_branch_with_a_reason(): void
    {
        foreach ([
            'push-branch-deleted.json' => 'deleted',
            'push-tag.json' => 'tag push',
            'push-other-branch.json' => 'feature/login',
        ] as $fixture => $reason) {
            $response = $this->receive($this->dataCenter($fixture, 'dc-' . $fixture));

            $this->assertSame(202, $response->status, $fixture);
            $delivery = HookDelivery::where('delivery_id', 'dc-' . $fixture)->firstOrFail();
            $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome, $fixture);
            $this->assertStringContainsString($reason, (string) $delivery->reason, $fixture);
        }

        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_data_center_push_of_several_refs_deploys_once(): void
    {
        $this->receive($this->dataCenter('push-multi-change.json'));

        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertSame('main', $delivery->branch);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_bitbucket_push_with_one_unreadable_change_still_deploys_the_tracked_branch(): void
    {
        $body = '{"changes":[{"type":"RENAME"},{"ref":{"id":"refs/heads/main","type":"BRANCH"},"toHash":"def456","type":"UPDATE"}]}';
        $request = $this->request($body, [
            'HTTP_X_EVENT_KEY' => 'repo:refs_changed',
            'HTTP_X_REQUEST_ID' => 'dc-mixed',
            'HTTP_X_HUB_SIGNATURE' => $this->sign($body),
        ]);

        $response = $this->receive($request);

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $delivery->outcome);
        $this->assertSame('def456', $delivery->commit);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_bitbucket_data_center_ping_is_200_and_ignored(): void
    {
        $response = $this->receive($this->dataCenter('ping.json', 'dc-ping', event: 'diagnostics:ping'));

        $this->assertSame(200, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertSame('ping', $delivery->reason);
        $this->assertSame('diagnostics:ping', $delivery->event);
        Queue::assertNothingPushed();
    }

    public function test_a_bad_or_missing_bitbucket_data_center_signature_is_401_and_rejected_even_for_a_ping(): void
    {
        $this->assertSame(401, $this->receive($this->dataCenter('push-main.json', 'a', 'wrong-secret'))->status);
        $this->assertSame(401, $this->receive($this->dataCenter('push-main.json', 'b', null))->status);
        $this->assertSame(401, $this->receive($this->dataCenter('ping.json', 'c', 'wrong-secret', 'diagnostics:ping'))->status);

        $this->assertSame(3, HookDelivery::where('outcome', HookDelivery::OUTCOME_REJECTED)->count());
        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_data_center_redelivery_is_202_and_deploys_nothing_more(): void
    {
        $this->receive($this->dataCenter('push-main.json', 'dc-same'));
        $again = $this->receive($this->dataCenter('push-main.json', 'dc-same'));

        $this->assertSame(202, $again->status);
        $this->assertSame(1, HookDelivery::count());
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_bitbucket_event_the_engine_does_not_act_on_is_202_and_ignored(): void
    {
        $response = $this->receive($this->cloud('push-main.json', 'cloud-pr', event: 'pullrequest:created'));

        $this->assertSame(202, $response->status);
        $delivery = $this->onlyDelivery();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertStringContainsString('pullrequest:created', (string) $delivery->reason);
        Queue::assertNothingPushed();
    }

    public function test_a_bitbucket_delivery_whose_signed_body_is_not_a_push_is_400_and_rejected(): void
    {
        $request = $this->request('not json', [
            'HTTP_X_EVENT_KEY' => 'repo:refs_changed',
            'HTTP_X_HUB_SIGNATURE' => $this->sign('not json'),
        ]);

        $response = $this->receive($request);

        $this->assertSame(400, $response->status);
        $this->assertSame(HookDelivery::OUTCOME_REJECTED, $this->onlyDelivery()->outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_hooks_history_is_capped_at_twenty_deliveries_keeping_the_newest(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->receive($this->github('ping', 'ping.json', "guid-cap-{$i}"));
        }

        $this->assertSame(HookDelivery::KEEP_HISTORY, HookDelivery::count());
        $remaining = HookDelivery::query()->pluck('delivery_id')->all();
        $this->assertNotContains('guid-cap-1', $remaining, 'the oldest delivery must have been pruned');
        $this->assertContains('guid-cap-25', $remaining, 'the newest delivery must survive');
    }

    /**
     * A bad signature needs only the URL. Twenty of them used to push every
     * real delivery out of the history -- and with it the row a queued job
     * was about to read, so the push vanished. They get a window of their own.
     */
    public function test_rejected_deliveries_never_push_accepted_ones_out_of_the_history(): void
    {
        $this->receive($this->github('push', 'push-main.json', 'guid-real'));

        for ($i = 1; $i <= 25; $i++) {
            $this->receive($this->github('push', 'push-main.json', "guid-junk-{$i}", secret: 'wrong'));
        }

        $this->assertSame(1, HookDelivery::where('delivery_id', 'guid-real')->count(), 'the accepted delivery must survive the flood');
        $this->assertSame(HookDelivery::KEEP_REJECTED, HookDelivery::where('outcome', HookDelivery::OUTCOME_REJECTED)->count());
    }

    public function test_a_delivery_still_waiting_for_its_job_is_never_pruned(): void
    {
        $this->receive($this->github('push', 'push-main.json', 'guid-in-flight'));

        for ($i = 1; $i <= 25; $i++) {
            $this->receive($this->github('ping', 'ping.json', "guid-cap-{$i}"));
        }

        $this->assertSame(1, HookDelivery::where('delivery_id', 'guid-in-flight')->count(), 'its job would find no row');
    }

    public function test_the_hooks_pending_delivery_is_never_pruned(): void
    {
        $pending = HookDelivery::create(['deploy_hook_id' => $this->hook->id, 'provider' => 'github', 'delivery_id' => 'guid-pending', 'event' => 'push', 'outcome' => HookDelivery::OUTCOME_COALESCED, 'result' => HookDelivery::RESULT_SUPERSEDED]);
        $this->hook->update(['pending_delivery_id' => $pending->id]);

        for ($i = 1; $i <= 25; $i++) {
            $this->receive($this->github('ping', 'ping.json', "guid-cap-{$i}"));
        }

        $this->assertNotNull(HookDelivery::find($pending->id));
    }

    public function test_a_hook_whose_secret_cannot_be_decrypted_verifies_nothing(): void
    {
        $this->hook->update(['secret_encrypted' => 'not-a-ciphertext']);

        $response = $this->receive($this->github('push', 'push-main.json'));

        $this->assertSame(401, $response->status);
        Queue::assertNothingPushed();
    }

    /**
     * A leaked or guessed hook URL can be hammered with wrong signatures
     * forever otherwise -- each one cheap to send, each one an HMAC the
     * engine has to compute. After 30 in a minute, the 31st is refused before
     * the signature is even looked at: a *valid* one, here, so the only way
     * it can still fail is the throttle firing first.
     */
    public function test_more_than_thirty_bad_signatures_in_a_minute_gets_429_without_verifying(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $response = $this->receive($this->github('push', 'push-main.json', "guid-flood-{$i}", secret: 'wrong'));
            $this->assertSame(401, $response->status, "attempt {$i}");
        }

        $response = $this->receive($this->github('push', 'push-main.json', 'guid-flood-valid'));

        $this->assertSame(429, $response->status);
        $this->assertSame(0, HookDelivery::where('delivery_id', 'guid-flood-valid')->count(), 'a throttled request is not recorded as a delivery');
        Queue::assertNothingPushed();
    }

    /**
     * Sending no recognisable provider headers at all costs nothing to send
     * (no HMAC to fake) and would otherwise write a row on every attempt, so
     * it has to count against the same throttle as a bad signature -- not a
     * cheaper way around it.
     */
    public function test_requests_with_no_recognised_provider_also_count_toward_the_throttle(): void
    {
        $request = $this->request('{"ref":"refs/heads/main"}', ['CONTENT_TYPE' => 'application/json']);

        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(400, $this->receive($request)->status, "attempt {$i}");
        }

        $this->assertSame(429, $this->receive($this->github('push', 'push-main.json', 'guid-unrecognised-then-valid'))->status);
        Queue::assertNothingPushed();
    }

    public function test_the_throttle_is_scoped_to_one_hook(): void
    {
        $other = $this->hook($this->user('main', username: 'carol'), self::SECRET);

        for ($i = 1; $i <= 30; $i++) {
            $this->receive($this->github('push', 'push-main.json', "guid-flood-{$i}", secret: 'wrong'));
        }
        $this->assertSame(429, $this->receive($this->github('push', 'push-main.json', 'guid-flood-more'))->status);

        $response = (new HookReceiver())->receive($other, $this->github('push', 'push-main.json', 'guid-other-hook'));

        $this->assertSame(202, $response->status, 'a flood against one hook must not throttle another');
        Queue::assertPushed(RunHookDelivery::class, 1);
    }
}
