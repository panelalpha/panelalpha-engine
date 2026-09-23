<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\BitbucketCloudProvider;
use App\Lib\DeployHook\HookEvent;
use App\Lib\DeployHook\InvalidPayload;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Bitbucket Cloud's side of the contract, against payloads shaped like the
 * ones its `repo:push` webhook sends (tests/fixtures/bitbucket-cloud-hooks).
 * The body is signed with `X-Hub-Signature: sha256=<hmac>`, the same scheme
 * as Data Center; what tells the two apart is Cloud's own request headers.
 */
class BitbucketCloudProviderTest extends TestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/bitbucket-cloud-hooks/' . $name);
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $body, array $server = []): Request
    {
        return Request::create('/hooks/opaque', 'POST', [], [], [], $server, $body);
    }

    private function push(string $file = 'push-main.json'): Request
    {
        $body = $this->fixture($file);

        return $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => 'repo:push',
            'HTTP_X_HOOK_UUID' => '{7b1e4f0a-0000-4000-8000-000000000001}',
            'HTTP_X_REQUEST_UUID' => '9c2d5e1b-0000-4000-8000-000000000001',
            'HTTP_X_ATTEMPT_NUMBER' => '1',
            'HTTP_X_HUB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET),
        ]);
    }

    public function test_it_recognises_cloud_by_its_event_key_and_its_own_uuid_headers(): void
    {
        $provider = new BitbucketCloudProvider();

        $this->assertSame('bitbucket-cloud', $provider->name());
        $this->assertTrue($provider->recognises($this->push()));
        $this->assertFalse($provider->recognises($this->request('{}')));
        $this->assertFalse($provider->recognises($this->request('{}', ['HTTP_X_GITHUB_EVENT' => 'push'])));
        $this->assertFalse(
            $provider->recognises($this->request('{}', ['HTTP_X_EVENT_KEY' => 'repo:refs_changed', 'HTTP_X_REQUEST_ID' => 'dc-1'])),
            'an event key with no Cloud UUID header is Data Center'
        );
    }

    public function test_the_right_signature_verifies(): void
    {
        $this->assertTrue((new BitbucketCloudProvider())->verify($this->push(), self::SECRET));
    }

    public function test_a_signature_made_with_another_secret_does_not_verify(): void
    {
        $this->assertFalse((new BitbucketCloudProvider())->verify($this->push(), 'some-other-secret'));
    }

    public function test_a_signature_over_another_body_does_not_verify(): void
    {
        $request = $this->request($this->fixture('push-tag.json'), [
            'HTTP_X_EVENT_KEY' => 'repo:push',
            'HTTP_X_HUB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $this->fixture('push-main.json'), self::SECRET),
        ]);

        $this->assertFalse((new BitbucketCloudProvider())->verify($request, self::SECRET));
    }

    public function test_a_missing_or_malformed_signature_does_not_verify(): void
    {
        $provider = new BitbucketCloudProvider();
        $body = $this->fixture('push-main.json');
        $valid = hash_hmac('sha256', $body, self::SECRET);

        foreach ([null, '', $valid, 'sha1=' . $valid, 'sha256=' . substr($valid, 1), 'sha256=' . $valid . 'a', 'sha256=zz' . substr($valid, 2)] as $header) {
            $server = ['HTTP_X_EVENT_KEY' => 'repo:push'];
            if ($header !== null) {
                $server['HTTP_X_HUB_SIGNATURE'] = $header;
            }

            $this->assertFalse($provider->verify($this->request($body, $server), self::SECRET), 'accepted [' . (string) $header . ']');
        }
    }

    public function test_it_parses_a_push_to_a_branch(): void
    {
        $event = (new BitbucketCloudProvider())->parse($this->push());

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame('bitbucket-cloud', $event->provider);
        $this->assertSame('repo:push', $event->event);
        $this->assertCount(1, $event->changes);
        $this->assertSame('refs/heads/main', $event->ref);
        $this->assertSame('main', $event->branch);
        $this->assertFalse($event->tag);
        $this->assertFalse($event->deleted);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->commit);
    }

    public function test_a_branch_name_with_a_slash_keeps_it(): void
    {
        $event = (new BitbucketCloudProvider())->parse($this->push('push-other-branch.json'));

        $this->assertSame('feature/login', $event->branch);
    }

    public function test_a_deleted_branch_has_a_null_new_and_the_name_comes_from_old(): void
    {
        $event = (new BitbucketCloudProvider())->parse($this->push('push-branch-deleted.json'));

        $this->assertTrue($event->deleted);
        $this->assertSame('main', $event->branch);
        $this->assertNull($event->commit, 'a deleted ref points at nothing');
    }

    public function test_a_new_tag_is_a_tag_and_not_a_branch(): void
    {
        $event = (new BitbucketCloudProvider())->parse($this->push('push-tag.json'));

        $this->assertTrue($event->tag);
        $this->assertNull($event->branch);
        $this->assertSame('refs/tags/v1.4.0', $event->ref);
    }

    public function test_every_change_in_the_payload_is_kept_in_order(): void
    {
        $event = (new BitbucketCloudProvider())->parse($this->push('push-multi-change.json'));

        $this->assertSame(
            [['v1.4.0', true], ['feature/login', false], ['main', false]],
            array_map(fn ($c) => [$c->tag ? substr((string) $c->ref, strlen('refs/tags/')) : $c->branch, $c->tag], $event->changes)
        );
        $this->assertSame('b71e0c2d9a4f6e3b5c8d1a7f0e2b4c6d8a9f1e3c', $event->changes[1]->commit);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->changes[2]->commit);
    }

    public function test_a_mercurial_reference_is_neither_a_branch_nor_a_tag(): void
    {
        $body = json_encode(['push' => ['changes' => [['old' => null, 'new' => ['type' => 'named_branch', 'name' => 'default', 'target' => ['hash' => 'abc']]]]]]);
        $request = $this->request((string) $body, ['HTTP_X_EVENT_KEY' => 'repo:push']);

        $change = (new BitbucketCloudProvider())->parse($request)->changes[0];

        $this->assertNull($change->branch);
        $this->assertFalse($change->tag);
    }

    public function test_an_event_it_does_not_act_on_is_other(): void
    {
        $request = $this->request('{"pullrequest":{}}', [
            'HTTP_X_EVENT_KEY' => 'pullrequest:created',
            'HTTP_X_REQUEST_UUID' => 'req-1',
        ]);

        $event = (new BitbucketCloudProvider())->parse($request);

        $this->assertSame(HookEvent::OTHER, $event->kind);
        $this->assertSame('pullrequest:created', $event->event);
        $this->assertSame([], $event->changes);
    }

    public function test_the_delivery_id_is_the_request_uuid(): void
    {
        $this->assertSame('9c2d5e1b-0000-4000-8000-000000000001', (new BitbucketCloudProvider())->parse($this->push())->deliveryId);
    }

    public function test_a_retry_is_the_same_request_uuid_with_a_higher_attempt_number(): void
    {
        $retry = $this->request($this->fixture('push-main.json'), [
            'HTTP_X_EVENT_KEY' => 'repo:push',
            'HTTP_X_REQUEST_UUID' => '9c2d5e1b-0000-4000-8000-000000000001',
            'HTTP_X_ATTEMPT_NUMBER' => '2',
        ]);

        $this->assertSame((new BitbucketCloudProvider())->parse($this->push())->deliveryId, (new BitbucketCloudProvider())->parse($retry)->deliveryId);
    }

    public function test_the_delivery_id_fits_its_column_whatever_the_sender_typed(): void
    {
        $request = $this->request($this->fixture('push-main.json'), [
            'HTTP_X_EVENT_KEY' => 'repo:push',
            'HTTP_X_REQUEST_UUID' => str_repeat('u', 500),
        ]);

        $this->assertSame(128, strlen((string) (new BitbucketCloudProvider())->parse($request)->deliveryId));
    }

    public function test_without_the_header_there_is_no_delivery_id(): void
    {
        $request = $this->request($this->fixture('push-main.json'), ['HTTP_X_EVENT_KEY' => 'repo:push']);

        $this->assertNull((new BitbucketCloudProvider())->parse($request)->deliveryId);
    }

    public function test_a_push_whose_body_is_not_json_is_an_invalid_payload(): void
    {
        $this->expectException(InvalidPayload::class);
        (new BitbucketCloudProvider())->parse($this->request('not json', ['HTTP_X_EVENT_KEY' => 'repo:push']));
    }

    public function test_a_push_without_changes_is_an_invalid_payload(): void
    {
        $this->expectException(InvalidPayload::class);
        (new BitbucketCloudProvider())->parse($this->request('{"push":{}}', ['HTTP_X_EVENT_KEY' => 'repo:push']));
    }

    public function test_a_change_that_names_no_reference_is_an_invalid_payload(): void
    {
        $this->expectException(InvalidPayload::class);
        (new BitbucketCloudProvider())->parse($this->request('{"push":{"changes":[{"new":null,"old":null}]}}', ['HTTP_X_EVENT_KEY' => 'repo:push']));
    }

    public function test_a_change_it_cannot_read_does_not_stop_the_others(): void
    {
        $body = '{"push":{"changes":[{"new":null,"old":null},"junk",{"old":null,"new":{"type":"branch","name":"main","target":{"hash":"abc"}}}]}}';

        $event = (new BitbucketCloudProvider())->parse($this->request($body, ['HTTP_X_EVENT_KEY' => 'repo:push']));

        $this->assertCount(1, $event->changes);
        $this->assertSame('main', $event->changes[0]->branch);
        $this->assertSame('abc', $event->changes[0]->commit);
    }

    public function test_a_push_with_an_empty_change_list_has_nothing_in_it(): void
    {
        $event = (new BitbucketCloudProvider())->parse($this->request('{"push":{"changes":[]}}', ['HTTP_X_EVENT_KEY' => 'repo:push']));

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame([], $event->changes);
    }
}
