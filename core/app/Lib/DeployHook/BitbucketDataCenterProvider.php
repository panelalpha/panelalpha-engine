<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * Bitbucket Data Center, recognised by an event key with no Cloud UUID header: `repo:refs_changed` and its
 * `changes[]`, `diagnostics:ping` as a ping, and the optional `X-Request-Id`, unique per request, as delivery id.
 */
final class BitbucketDataCenterProvider extends BitbucketProvider
{
    private const NULL_SHA = '0000000000000000000000000000000000000000';

    public function name(): string
    {
        return 'bitbucket-data-center';
    }

    public function recognises(Request $request): bool
    {
        return $request->headers->has('X-Event-Key') && ! $this->hasCloudHeaders($request);
    }

    public function parse(Request $request): HookEvent
    {
        $event = $this->eventKey($request);
        $delivery = $this->requestId($request, 'X-Request-Id');

        if ($event === 'diagnostics:ping') {
            return new HookEvent($this->name(), HookEvent::PING, $event, $delivery);
        }

        if ($event !== 'repo:refs_changed') {
            return new HookEvent($this->name(), HookEvent::OTHER, $event, $delivery);
        }

        return $this->pushEvent(
            $event,
            $delivery,
            $this->payload($request)['changes'] ?? null,
            fn (mixed $change) => $this->change($change),
        );
    }

    private function change(mixed $change): ?RefChange
    {
        $id = is_array($change) ? ($change['ref']['id'] ?? $change['refId'] ?? null) : null;
        $type = is_array($change) ? ($change['type'] ?? null) : null;
        if (! is_string($id) || $id === '' || ! in_array($type, ['ADD', 'UPDATE', 'DELETE'], true)) {
            return null;
        }

        $to = is_string($change['toHash'] ?? null) ? $change['toHash'] : null;
        $deleted = $type === 'DELETE' || $to === self::NULL_SHA;

        return new RefChange(
            ref: $id,
            branch: str_starts_with($id, 'refs/heads/') ? substr($id, strlen('refs/heads/')) : null,
            tag: str_starts_with($id, 'refs/tags/'),
            deleted: $deleted,
            commit: $deleted ? null : $to,
        );
    }
}
