<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * Bitbucket Data Center (self-hosted). `repo:refs_changed` is the push event,
 * and its body is `changes[]`, one entry per ref the push moved:
 *
 *  - `ref.id` is the full ref (`refs/heads/main`), `ref.type` is `BRANCH` or
 *    `TAG`;
 *  - `type` is `ADD`, `UPDATE` or `DELETE`, and `toHash` is the all-zero hash
 *    for a `DELETE`.
 *
 * The "Test connection" button in the webhook settings sends
 * `diagnostics:ping`, which is answered like GitHub's ping.
 *
 * `X-Request-Id` is "a unique UUID for each webhook request" and is the
 * delivery id. It is not a fingerprint of the push: a hook that fires again
 * for the same push is a different request.
 *
 * Recognised by an event key with no Cloud UUID header. Atlassian says
 * `X-Request-Id` "may" be present, so it is not required.
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
        return $request->headers->has('X-Event-Key') && !$this->hasCloudHeaders($request);
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
        if (!is_string($id) || $id === '' || !in_array($type, ['ADD', 'UPDATE', 'DELETE'], true)) {
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
