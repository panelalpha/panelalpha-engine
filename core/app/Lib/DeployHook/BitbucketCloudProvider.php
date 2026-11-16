<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * Bitbucket Cloud: `repo:push` with `push.changes[]`, each full ref rebuilt from its Git type (Mercurial skipped).
 * `X-Request-UUID` is the delivery id; a retry with a fresh one still carries the same signed body, acted on once.
 */
final class BitbucketCloudProvider extends BitbucketProvider
{
    public function name(): string
    {
        return 'bitbucket-cloud';
    }

    public function recognises(Request $request): bool
    {
        return $request->headers->has('X-Event-Key') && $this->hasCloudHeaders($request);
    }

    public function parse(Request $request): HookEvent
    {
        $event = $this->eventKey($request);
        $delivery = $this->requestId($request, 'X-Request-UUID');

        if ($event !== 'repo:push') {
            return new HookEvent($this->name(), HookEvent::OTHER, $event, $delivery);
        }

        return $this->pushEvent(
            $event,
            $delivery,
            $this->payload($request)['push']['changes'] ?? null,
            fn (mixed $change) => $this->change($change),
        );
    }

    /**
     * One `push.changes[]` entry, or null when it cannot be read. A deletion has
     * no `new`, so the ref it removed is described by `old`.
     */
    private function change(mixed $change): ?RefChange
    {
        if (! is_array($change)) {
            return null;
        }

        $new = is_array($change['new'] ?? null) ? $change['new'] : null;
        $old = is_array($change['old'] ?? null) ? $change['old'] : null;

        $described = $new ?? $old ?? [];
        $name = $described['name'] ?? null;
        $type = $described['type'] ?? null;
        if (! is_string($name) || $name === '' || ! is_string($type)) {
            return null;
        }

        return $this->refChange($type, $name, $new);
    }

    /**
     * @param  ?array<string, mixed>  $new  the ref after the push; null when the push deleted it
     */
    private function refChange(string $type, string $name, ?array $new): RefChange
    {
        $deleted = $new === null;
        $hash = $new['target']['hash'] ?? null;
        $commit = ! $deleted && is_string($hash) ? $hash : null;

        if (in_array($type, ['tag', 'annotated_tag'], true)) {
            return new RefChange(ref: 'refs/tags/'.$name, tag: true, deleted: $deleted, commit: $commit);
        }

        if ($type !== 'branch') {
            return new RefChange(ref: $name, deleted: $deleted);
        }

        return new RefChange(ref: 'refs/heads/'.$name, branch: $name, deleted: $deleted, commit: $commit);
    }
}
