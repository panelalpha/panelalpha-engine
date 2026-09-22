<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\GithubProvider;
use App\Lib\DeployHook\HookEvent;
use App\Lib\DeployHook\InvalidPayload;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * GitHub's side of the contract, against payloads shaped like the ones GitHub
 * sends (tests/fixtures/github-hooks). Signatures are computed here rather
 * than recorded: the secret is the test's own, and what matters is that the
 * HMAC covers the bytes on the wire.
 */
class GithubProviderTest extends TestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

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
    private function request(string $body, array $server = []): Request
    {
        return Request::create('/hooks/opaque', 'POST', [], [], [], $server, $body);
    }

    private function pushRequest(string $file = 'push-main.json', string $delivery = 'f00d-0001'): Request
    {
        $body = $this->fixture($file);

        return $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);
    }

    /**
     * What GitHub sends when the webhook's content type is
     * application/x-www-form-urlencoded: the JSON, urlencoded into one
     * `payload` field. The signature is over that whole encoded body.
     */
    private function formRequest(string $file): Request
    {
        $body = 'payload=' . rawurlencode($this->fixture($file));

        return $this->request($body, [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'f00d-0002',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);
    }

    public function test_it_recognises_a_request_by_its_event_header(): void
    {
        $provider = new GithubProvider();

        $this->assertTrue($provider->recognises($this->pushRequest()));
        $this->assertFalse($provider->recognises($this->request('{}')));
        $this->assertFalse($provider->recognises($this->request('{}', ['HTTP_X_GITLAB_EVENT' => 'Push Hook'])));
    }

    public function test_a_correct_signature_verifies(): void
    {
        $this->assertTrue((new GithubProvider())->verify($this->pushRequest(), self::SECRET));
    }

    public function test_a_signature_made_with_another_secret_does_not_verify(): void
    {
        $this->assertFalse((new GithubProvider())->verify($this->pushRequest(), 'some-other-secret'));
    }

    public function test_a_missing_signature_does_not_verify(): void
    {
        $request = $this->request($this->fixture('push-main.json'), [
            'HTTP_X_GITHUB_EVENT' => 'push',
        ]);

        $this->assertFalse((new GithubProvider())->verify($request, self::SECRET));
    }

    public function test_a_malformed_signature_does_not_verify(): void
    {
        $body = $this->fixture('push-main.json');

        foreach (['', 'sha256=', 'sha256=zz', hash_hmac('sha256', $body, self::SECRET), 'sha1=' . hash_hmac('sha1', $body, self::SECRET)] as $header) {
            $request = $this->request($body, [
                'HTTP_X_GITHUB_EVENT' => 'push',
                'HTTP_X_HUB_SIGNATURE_256' => $header,
            ]);

            $this->assertFalse((new GithubProvider())->verify($request, self::SECRET), "accepted [{$header}]");
        }
    }

    public function test_the_signature_covers_the_raw_body_not_a_re_encoding_of_it(): void
    {
        $body = $this->fixture('push-main.json');
        // Same JSON, different bytes: a re-encoded body would still verify.
        $reformatted = json_encode(json_decode($body, true), JSON_UNESCAPED_SLASHES);
        $this->assertNotSame($body, $reformatted);

        $request = $this->request($reformatted, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);

        $this->assertFalse((new GithubProvider())->verify($request, self::SECRET));
    }

    public function test_a_form_encoded_signature_is_over_the_encoded_body(): void
    {
        $this->assertTrue((new GithubProvider())->verify($this->formRequest('push-main.json'), self::SECRET));
    }

    public function test_it_parses_a_branch_push(): void
    {
        $event = (new GithubProvider())->parse($this->pushRequest());

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame('github', $event->provider);
        $this->assertSame('push', $event->event);
        $this->assertSame('f00d-0001', $event->deliveryId);
        $this->assertSame('refs/heads/main', $event->ref);
        $this->assertSame('main', $event->branch);
        $this->assertFalse($event->tag);
        $this->assertFalse($event->deleted);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->commit);
    }

    public function test_it_parses_a_form_encoded_push_the_same_way(): void
    {
        $event = (new GithubProvider())->parse($this->formRequest('push-main.json'));

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertSame('main', $event->branch);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $event->commit);
    }

    public function test_a_branch_name_with_a_slash_keeps_it(): void
    {
        $event = (new GithubProvider())->parse($this->pushRequest('push-other-branch.json'));

        $this->assertSame('feature/login', $event->branch);
    }

    public function test_it_parses_a_tag_push(): void
    {
        $event = (new GithubProvider())->parse($this->pushRequest('push-tag.json'));

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertTrue($event->tag);
        $this->assertNull($event->branch);
        $this->assertSame('refs/tags/v1.4.0', $event->ref);
    }

    public function test_it_parses_a_branch_deletion(): void
    {
        $event = (new GithubProvider())->parse($this->pushRequest('push-branch-deleted.json'));

        $this->assertSame(HookEvent::PUSH, $event->kind);
        $this->assertTrue($event->deleted);
        $this->assertSame('main', $event->branch);
    }

    public function test_it_parses_a_ping(): void
    {
        $body = $this->fixture('ping.json');
        $request = $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_GITHUB_DELIVERY' => 'f00d-0003',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ]);

        $event = (new GithubProvider())->parse($request);

        $this->assertSame(HookEvent::PING, $event->kind);
        $this->assertSame('ping', $event->event);
        $this->assertNull($event->branch);
    }

    public function test_an_event_it_does_not_act_on_is_other(): void
    {
        $body = '{"action":"opened"}';
        $request = $this->request($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'issues',
            'HTTP_X_GITHUB_DELIVERY' => 'f00d-0004',
        ]);

        $event = (new GithubProvider())->parse($request);

        $this->assertSame(HookEvent::OTHER, $event->kind);
        $this->assertSame('issues', $event->event);
    }

    public function test_a_push_whose_body_is_not_json_is_an_invalid_payload(): void
    {
        $request = $this->request('not json at all', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
        ]);

        $this->expectException(InvalidPayload::class);
        (new GithubProvider())->parse($request);
    }

    public function test_a_form_push_without_a_payload_field_is_an_invalid_payload(): void
    {
        $request = $this->request('other=1', [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_EVENT' => 'push',
        ]);

        $this->expectException(InvalidPayload::class);
        (new GithubProvider())->parse($request);
    }

    public function test_a_push_without_a_ref_is_an_invalid_payload(): void
    {
        $request = $this->request('{"after":"abc"}', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
        ]);

        $this->expectException(InvalidPayload::class);
        (new GithubProvider())->parse($request);
    }
}
