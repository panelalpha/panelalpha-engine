<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\GitlabProvider;
use App\Lib\DeployHook\HookEvent;
use App\Lib\DeployHook\InvalidPayload;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * GitLab's side of the contract, against payloads shaped like the ones GitLab
 * (gitlab.com and self-managed) sends (tests/fixtures/gitlab-hooks). Unlike
 * GitHub there is no signature: the secret token comes back verbatim in
 * `X-Gitlab-Token`, so verification is a constant-time string comparison and
 * says nothing about the body.
 */
class GitlabProviderTest extends TestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/gitlab-hooks/' . $name);
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $body, array $server = []): Request
    {
        return Request::create('/hooks/opaque', 'POST', [], [], [], $server, $body);
    }

    private function pushRequest(string $file = 'push-main.json', string $event = 'Push Hook'): Request
    {
        return $this->request($this->fixture($file), [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => $event,
            'HTTP_X_GITLAB_TOKEN' => self::SECRET,
            'HTTP_X_GITLAB_EVENT_UUID' => 'e0c2a4b6-0000-4000-8000-000000000001',
            'HTTP_IDEMPOTENCY_KEY' => '7d6f1c1e-0000-4000-8000-000000000001',
        ]);
    }

    public function test_it_recognises_a_request_by_its_event_header(): void
    {
        $provider = new GitlabProvider();

        $this->assertSame('gitlab', $provider->name());
        $this->assertTrue($provider->recognises($this->pushRequest()));
        $this->assertFalse($provider->recognises($this->request('{}')));
        $this->assertFalse($provider->recognises($this->request('{}', ['HTTP_X_GITHUB_EVENT' => 'push'])));
    }

    public function test_the_right_token_verifies(): void
    {
        $this->assertTrue((new GitlabProvider())->verify($this->pushRequest(), self::SECRET));
    }

    public function test_a_wrong_token_does_not_verify(): void
    {
        $this->assertFalse((new GitlabProvider())->verify($this->pushRequest(), 'some-other-secret'));
    }

    public function test_a_missing_or_empty_token_does_not_verify(): void
    {
        $provider = new GitlabProvider();
        $body = $this->fixture('push-main.json');

        $this->assertFalse($provider->verify($this->request($body, ['HTTP_X_GITLAB_EVENT' => 'Push Hook']), self::SECRET));
        $this->assertFalse($provider->verify($this->request($body, ['HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => '']), self::SECRET));
    }

    public function test_a_token_that_only_starts_or_ends_like_the_secret_does_not_verify(): void
    {
        $provider = new GitlabProvider();

        foreach ([self::SECRET . 'x', 'x' . self::SECRET, substr(self::SECRET, 1), substr(self::SECRET, 0, -1), strtoupper(self::SECRET)] as $token) {
            $request = $this->request('{}', ['HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => $token]);

            $this->assertFalse($provider->verify($request, self::SECRET), "accepted [{$token}]");
        }
    }

    public function test_it_never_verifies_against_an_empty_secret(): void
    {
        // Nothing to compare with must not mean "an absent header matches".
        $request = $this->request('{}', ['HTTP_X_GITLAB_EVENT' => 'Push Hook']);

        $this->assertFalse((new GitlabProvider())->verify($request, ''));
    }

    public function test_it_parses_a_branch_push(): void
    {
        $event = (new GitlabProvider())->parse($this->pushRequest());

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame('gitlab', $event->provider);
        $this->assertSame('Push Hook', $event->event);
        $this->assertSame('refs/heads/main', $event->ref);
        $this->assertSame('main', $event->branch);
        $this->assertFalse($event->tag);
        $this->assertFalse($event->deleted);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->commit);
    }

    public function test_a_branch_name_with_a_slash_keeps_it(): void
    {
        $event = (new GitlabProvider())->parse($this->pushRequest('push-other-branch.json'));

        $this->assertSame('feature/login', $event->branch);
    }

    public function test_it_parses_a_tag_push(): void
    {
        $event = (new GitlabProvider())->parse($this->pushRequest('push-tag.json', 'Tag Push Hook'));

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame('Tag Push Hook', $event->event);
        $this->assertTrue($event->tag);
        $this->assertNull($event->branch);
        $this->assertSame('refs/tags/v1.4.0', $event->ref);
    }

    public function test_it_parses_a_branch_deletion(): void
    {
        $event = (new GitlabProvider())->parse($this->pushRequest('push-branch-deleted.json'));

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertTrue($event->deleted);
        $this->assertSame('main', $event->branch);
        $this->assertNull($event->commit, 'a deleted ref points at nothing');
    }

    public function test_an_event_it_does_not_act_on_is_other(): void
    {
        $request = $this->request('{"object_kind":"merge_request"}', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'Merge Request Hook',
            'HTTP_IDEMPOTENCY_KEY' => 'k-1',
        ]);

        $event = (new GitlabProvider())->parse($request);

        $this->assertSame(HookEvent::OTHER, $event->kind);
        $this->assertSame('Merge Request Hook', $event->event);
    }

    public function test_the_delivery_id_is_the_key_gitlab_keeps_across_retries(): void
    {
        $event = (new GitlabProvider())->parse($this->pushRequest());

        $this->assertSame('7d6f1c1e-0000-4000-8000-000000000001', $event->deliveryId);
    }

    public function test_without_that_key_the_delivery_id_is_the_event_uuid_of_this_very_ref_update(): void
    {
        // One `git push main feature` is one request and so one event UUID, but
        // two push events. Keyed on the UUID alone, the second would look like a
        // redelivery of the first and a deploy of `main` could be swallowed.
        $main = $this->request($this->fixture('push-main.json'), [
            'HTTP_X_GITLAB_EVENT' => 'Push Hook',
            'HTTP_X_GITLAB_EVENT_UUID' => 'same-uuid',
        ]);
        $feature = $this->request($this->fixture('push-other-branch.json'), [
            'HTTP_X_GITLAB_EVENT' => 'Push Hook',
            'HTTP_X_GITLAB_EVENT_UUID' => 'same-uuid',
        ]);

        $provider = new GitlabProvider();
        $first = $provider->parse($main)->deliveryId;
        $second = $provider->parse($feature)->deliveryId;

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $provider->parse($main)->deliveryId, 'the same update keeps the same id');
        $this->assertLessThanOrEqual(128, strlen((string) $first), 'must fit the delivery_id column');
    }

    public function test_with_neither_header_there_is_no_delivery_id(): void
    {
        $request = $this->request($this->fixture('push-main.json'), ['HTTP_X_GITLAB_EVENT' => 'Push Hook']);

        $this->assertNull((new GitlabProvider())->parse($request)->deliveryId);
    }

    public function test_a_push_whose_body_is_not_json_is_an_invalid_payload(): void
    {
        $request = $this->request('not json at all', ['HTTP_X_GITLAB_EVENT' => 'Push Hook']);

        $this->expectException(InvalidPayload::class);
        (new GitlabProvider())->parse($request);
    }

    public function test_a_push_without_a_ref_is_an_invalid_payload(): void
    {
        $request = $this->request('{"after":"abc"}', ['HTTP_X_GITLAB_EVENT' => 'Push Hook']);

        $this->expectException(InvalidPayload::class);
        (new GitlabProvider())->parse($request);
    }
}
