<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\BitbucketDataCenterProvider;
use App\Lib\DeployHook\HookEvent;
use App\Lib\DeployHook\InvalidPayload;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Bitbucket Data Center's side of the contract, against payloads shaped like
 * the ones `repo:refs_changed` sends (tests/fixtures/bitbucket-dc-hooks). The
 * signature is `X-Hub-Signature: sha256=<hmac>`, as on Cloud.
 */
class BitbucketDataCenterProviderTest extends TestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/bitbucket-dc-hooks/' . $name);
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $body, array $server = []): Request
    {
        return Request::create('/hooks/opaque', 'POST', [], [], [], $server, $body);
    }

    private function push(string $file = 'push-main.json', string $event = 'repo:refs_changed'): Request
    {
        $body = $this->fixture($file);

        return $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => $event,
            'HTTP_X_REQUEST_ID' => '5e3f7a9c-0000-4000-8000-000000000001',
            'HTTP_X_HUB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET),
        ]);
    }

    public function test_it_recognises_data_center_by_its_event_key_and_no_cloud_headers(): void
    {
        $provider = new BitbucketDataCenterProvider();

        $this->assertSame('bitbucket-data-center', $provider->name());
        $this->assertTrue($provider->recognises($this->push()));
        $this->assertTrue($provider->recognises($this->push('ping.json', 'diagnostics:ping')));
        $this->assertFalse($provider->recognises($this->request('{}')));
        $this->assertFalse($provider->recognises($this->request('{}', ['HTTP_X_GITHUB_EVENT' => 'push'])));
        $this->assertFalse(
            $provider->recognises($this->request('{}', ['HTTP_X_EVENT_KEY' => 'repo:push', 'HTTP_X_REQUEST_UUID' => 'cloud-1'])),
            'Cloud sends its own UUID headers'
        );
    }

    public function test_the_right_signature_verifies(): void
    {
        $this->assertTrue((new BitbucketDataCenterProvider())->verify($this->push(), self::SECRET));
    }

    public function test_a_signature_made_with_another_secret_does_not_verify(): void
    {
        $this->assertFalse((new BitbucketDataCenterProvider())->verify($this->push(), 'some-other-secret'));
    }

    public function test_a_missing_or_malformed_signature_does_not_verify(): void
    {
        $provider = new BitbucketDataCenterProvider();
        $body = $this->fixture('push-main.json');
        $valid = hash_hmac('sha256', $body, self::SECRET);

        foreach ([null, '', $valid, 'sha1=' . $valid, 'sha256=' . substr($valid, 1)] as $header) {
            $server = ['HTTP_X_EVENT_KEY' => 'repo:refs_changed'];
            if ($header !== null) {
                $server['HTTP_X_HUB_SIGNATURE'] = $header;
            }

            $this->assertFalse($provider->verify($this->request($body, $server), self::SECRET), 'accepted [' . (string) $header . ']');
        }
    }

    public function test_an_update_of_a_branch_is_a_push(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->push());

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame('bitbucket-data-center', $event->provider);
        $this->assertSame('repo:refs_changed', $event->event);
        $this->assertSame('refs/heads/main', $event->ref);
        $this->assertSame('main', $event->branch);
        $this->assertFalse($event->tag);
        $this->assertFalse($event->deleted);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->commit);
    }

    public function test_creating_a_branch_is_a_push_of_it(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->push('push-branch-added.json'));

        $this->assertSame('main', $event->branch);
        $this->assertFalse($event->deleted);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->commit);
    }

    public function test_a_branch_name_with_a_slash_keeps_it(): void
    {
        $this->assertSame('feature/login', (new BitbucketDataCenterProvider())->parse($this->push('push-other-branch.json'))->branch);
    }

    public function test_a_delete_is_a_deletion_with_no_commit(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->push('push-branch-deleted.json'));

        $this->assertTrue($event->deleted);
        $this->assertSame('main', $event->branch);
        $this->assertNull($event->commit);
    }

    public function test_a_tag_is_a_tag_and_not_a_branch(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->push('push-tag.json'));

        $this->assertTrue($event->tag);
        $this->assertNull($event->branch);
        $this->assertSame('refs/tags/v1.4.0', $event->ref);
    }

    public function test_every_change_in_the_payload_is_kept_in_order(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->push('push-multi-change.json'));

        $this->assertSame(
            ['refs/tags/v1.4.0', 'refs/heads/feature/login', 'refs/heads/main'],
            array_map(fn ($c) => $c->ref, $event->changes)
        );
        $this->assertSame('b71e0c2d9a4f6e3b5c8d1a7f0e2b4c6d8a9f1e3c', $event->changes[1]->commit);
    }

    public function test_diagnostics_ping_is_a_ping(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->push('ping.json', 'diagnostics:ping'));

        $this->assertSame(HookEvent::PING, $event->kind);
        $this->assertSame('diagnostics:ping', $event->event);
        $this->assertSame('5e3f7a9c-0000-4000-8000-000000000001', $event->deliveryId);
    }

    public function test_an_event_it_does_not_act_on_is_other(): void
    {
        $event = (new BitbucketDataCenterProvider())->parse($this->request('{"eventKey":"pr:opened"}', ['HTTP_X_EVENT_KEY' => 'pr:opened']));

        $this->assertSame(HookEvent::OTHER, $event->kind);
        $this->assertSame('pr:opened', $event->event);
    }

    public function test_the_delivery_id_is_the_request_id_and_fits_its_column(): void
    {
        $this->assertSame('5e3f7a9c-0000-4000-8000-000000000001', (new BitbucketDataCenterProvider())->parse($this->push())->deliveryId);

        $long = $this->request($this->fixture('push-main.json'), ['HTTP_X_EVENT_KEY' => 'repo:refs_changed', 'HTTP_X_REQUEST_ID' => str_repeat('r', 500)]);
        $this->assertSame(128, strlen((string) (new BitbucketDataCenterProvider())->parse($long)->deliveryId));

        $none = $this->request($this->fixture('push-main.json'), ['HTTP_X_EVENT_KEY' => 'repo:refs_changed']);
        $this->assertNull((new BitbucketDataCenterProvider())->parse($none)->deliveryId);
    }

    public function test_a_push_whose_body_is_not_json_is_an_invalid_payload(): void
    {
        $this->expectException(InvalidPayload::class);
        (new BitbucketDataCenterProvider())->parse($this->request('not json', ['HTTP_X_EVENT_KEY' => 'repo:refs_changed']));
    }

    public function test_a_push_without_a_changes_list_is_an_invalid_payload(): void
    {
        $this->expectException(InvalidPayload::class);
        (new BitbucketDataCenterProvider())->parse($this->request('{"eventKey":"repo:refs_changed"}', ['HTTP_X_EVENT_KEY' => 'repo:refs_changed']));
    }

    public function test_a_change_it_cannot_read_does_not_stop_the_others(): void
    {
        $body = '{"changes":[{"toHash":"abc","type":"UPDATE"},{"ref":{"id":"refs/heads/main"},"toHash":"abc","type":"RENAME"},'
            . '{"ref":{"id":"refs/heads/main","type":"BRANCH"},"toHash":"def","type":"UPDATE"}]}';

        $event = (new BitbucketDataCenterProvider())->parse($this->request($body, ['HTTP_X_EVENT_KEY' => 'repo:refs_changed']));

        $this->assertCount(1, $event->changes);
        $this->assertSame('main', $event->changes[0]->branch);
        $this->assertSame('def', $event->changes[0]->commit);
    }

    public function test_a_change_without_a_ref_or_with_an_unknown_type_is_an_invalid_payload(): void
    {
        foreach ([
            '{"changes":[{"toHash":"abc","type":"UPDATE"}]}',
            '{"changes":[{"ref":{"id":"refs/heads/main","type":"BRANCH"},"toHash":"abc","type":"RENAME"}]}',
        ] as $body) {
            try {
                (new BitbucketDataCenterProvider())->parse($this->request($body, ['HTTP_X_EVENT_KEY' => 'repo:refs_changed']));
                $this->fail('accepted ' . $body);
            } catch (InvalidPayload) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
