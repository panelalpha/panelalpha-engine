<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\GithubProvider;
use App\Lib\DeployHook\GitlabProvider;
use App\Lib\DeployHook\HookEvent;
use App\Lib\DeployHook\HookProvider;
use App\Lib\DeployHook\InvalidPayload;
use App\Lib\DeployHook\RefChange;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every field of the event GitHub and GitLab pushes become, for the ref and `after`
 * shapes the fixtures do not cover, and the exact refusal of a push the event cannot be built from.
 */
class PushEventShapeTest extends TestCase
{
    private const SHA = 'a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c';

    private const NULL_SHA = '0000000000000000000000000000000000000000';

    private static function github(string $body, string $contentType = 'application/json'): Request
    {
        return Request::create('/hooks/opaque', 'POST', [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'gh-1',
        ], $body);
    }

    private static function gitlab(string $body, string $event = 'Push Hook', array $headers = []): Request
    {
        return Request::create('/hooks/opaque', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => $event,
        ] + $headers, $body);
    }

    /** @return array<string, mixed> */
    private static function fields(HookEvent $event): array
    {
        return [
            'provider' => $event->provider,
            'kind' => $event->kind,
            'event' => $event->event,
            'deliveryId' => $event->deliveryId,
            'ref' => $event->ref,
            'branch' => $event->branch,
            'tag' => $event->tag,
            'deleted' => $event->deleted,
            'commit' => $event->commit,
            'changes' => array_map(static fn (RefChange $c): array => (array) $c, $event->changes),
        ];
    }

    /** @return array<string, mixed> */
    private static function push(string $provider, string $event, ?string $delivery, string $ref, ?string $branch, bool $tag, bool $deleted, ?string $commit): array
    {
        return [
            'provider' => $provider,
            'kind' => HookEvent::PUSH,
            'event' => $event,
            'deliveryId' => $delivery,
            'ref' => $ref,
            'branch' => $branch,
            'tag' => $tag,
            'deleted' => $deleted,
            'commit' => $commit,
            'changes' => [(array) new RefChange($ref, $branch, $tag, $deleted, $commit)],
        ];
    }

    /** @return array<string, array{Request, HookProvider, array<string, mixed>}> */
    public static function pushes(): array
    {
        $gh = new GithubProvider();
        $gl = new GitlabProvider();
        $uuid = ['HTTP_X_GITLAB_EVENT_UUID' => 'uuid-1'];

        return [
            'github branch' => [self::github(json_encode(['ref' => 'refs/heads/main', 'after' => self::SHA])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/heads/main', 'main', false, false, self::SHA)],
            'github deleted flag keeps no commit' => [self::github(json_encode(['ref' => 'refs/heads/x', 'after' => self::SHA, 'deleted' => true])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/heads/x', 'x', false, true, null)],
            'github deleted must be true itself' => [self::github(json_encode(['ref' => 'refs/heads/x', 'after' => self::SHA, 'deleted' => 1])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/heads/x', 'x', false, false, self::SHA)],
            'github null sha' => [self::github(json_encode(['ref' => 'refs/tags/v1', 'after' => self::NULL_SHA])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/tags/v1', null, true, true, null)],
            'github after not a string' => [self::github(json_encode(['ref' => 'refs/heads/main', 'after' => 12])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/heads/main', 'main', false, false, null)],
            'github other ref' => [self::github(json_encode(['ref' => 'refs/pull/7/head'])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/pull/7/head', null, false, false, null)],
            'github bare heads' => [self::github(json_encode(['ref' => 'refs/heads/'])), $gh,
                self::push('github', 'push', 'gh-1', 'refs/heads/', '', false, false, null)],
            'github form' => [self::github('payload='.rawurlencode(json_encode(['ref' => 'refs/heads/a/b', 'after' => 'abc'])), 'application/x-www-form-urlencoded; charset=utf-8'), $gh,
                self::push('github', 'push', 'gh-1', 'refs/heads/a/b', 'a/b', false, false, 'abc')],
            'gitlab branch' => [self::gitlab(json_encode(['ref' => 'refs/heads/main', 'after' => self::SHA])), $gl,
                self::push('gitlab', 'Push Hook', null, 'refs/heads/main', 'main', false, false, self::SHA)],
            'gitlab deleted flag is ignored' => [self::gitlab(json_encode(['ref' => 'refs/heads/x', 'after' => self::SHA, 'deleted' => true])), $gl,
                self::push('gitlab', 'Push Hook', null, 'refs/heads/x', 'x', false, false, self::SHA)],
            'gitlab tag deletion' => [self::gitlab(json_encode(['ref' => 'refs/tags/v2', 'after' => self::NULL_SHA]), 'Tag Push Hook', $uuid), $gl,
                self::push('gitlab', 'Tag Push Hook', hash('sha256', "uuid-1\nrefs/tags/v2\n".self::NULL_SHA), 'refs/tags/v2', null, true, true, null)],
            'gitlab after not a string' => [self::gitlab(json_encode(['ref' => 'refs/heads/main', 'after' => ['x']]), 'Push Hook', $uuid), $gl,
                self::push('gitlab', 'Push Hook', hash('sha256', "uuid-1\nrefs/heads/main\n"), 'refs/heads/main', 'main', false, false, null)],
            'gitlab keyed delivery' => [self::gitlab(json_encode(['ref' => 'refs/merge-requests/1']), 'Push Hook', ['HTTP_IDEMPOTENCY_KEY' => 'key-1'] + $uuid), $gl,
                self::push('gitlab', 'Push Hook', 'key-1', 'refs/merge-requests/1', null, false, false, null)],
        ];
    }

    /** @param  array<string, mixed>  $expected */
    #[DataProvider('pushes')]
    public function test_a_push_becomes_the_same_event(Request $request, HookProvider $provider, array $expected): void
    {
        $this->assertSame($expected, self::fields($provider->parse($request)));
    }

    /** @return array<string, array{Request, HookProvider, string}> */
    public static function invalid(): array
    {
        $gh = new GithubProvider();
        $gl = new GitlabProvider();

        return [
            'github not json' => [self::github('{"ref":'), $gh, 'The push body is not JSON.'],
            'github json scalar' => [self::github('"refs/heads/main"'), $gh, 'The push body is not JSON.'],
            'github empty ref' => [self::github(json_encode(['ref' => ''])), $gh, 'A push without a ref.'],
            'github ref not a string' => [self::github(json_encode(['ref' => ['refs/heads/main']])), $gh, 'A push without a ref.'],
            'github form without payload' => [self::github('other=1', 'application/x-www-form-urlencoded'), $gh, 'A form-encoded push without a payload field.'],
            'github form payload not json' => [self::github('payload=nope', 'application/x-www-form-urlencoded'), $gh, 'The push body is not JSON.'],
            'gitlab not json' => [self::gitlab('nope'), $gl, 'The push body is not JSON.'],
            'gitlab json scalar' => [self::gitlab('5', 'Tag Push Hook'), $gl, 'The push body is not JSON.'],
            'gitlab no ref' => [self::gitlab(json_encode(['after' => self::SHA])), $gl, 'A push without a ref.'],
            'gitlab ref not a string' => [self::gitlab(json_encode(['ref' => 5])), $gl, 'A push without a ref.'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_a_push_the_event_cannot_be_built_from_is_refused_as_before(Request $request, HookProvider $provider, string $message): void
    {
        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage($message);

        $provider->parse($request);
    }
}
