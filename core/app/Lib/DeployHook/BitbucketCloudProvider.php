<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * Bitbucket Cloud (bitbucket.org). `repo:push` is the push event, and its body
 * is `push.changes[]`, one entry per ref the push moved:
 *
 *  - `new` is the ref after the push and is null when the branch was deleted;
 *  - `old` is the ref before and is null when it was just created;
 *  - each has a `type` (`branch`, `tag`, `annotated_tag` for Git), a `name`
 *    and `target.hash`.
 *
 * Cloud sends no full ref, so `refs/heads/<name>` and `refs/tags/<name>` are
 * rebuilt from the type. A Mercurial reference (`named_branch`, `bookmark`) is
 * neither, and the engine deploys Git only.
 *
 * `X-Request-UUID` identifies the request, and is the delivery id. Bitbucket
 * documents it only as "the UUID of the request", next to an
 * `X-Attempt-Number` that counts the retries of one payload, so a retry is
 * taken to repeat it. If a retry ever arrives with a fresh one it is a second
 * deploy of the same commit, which pulls nothing new.
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

    private function change(mixed $change): ?RefChange
    {
        if (!is_array($change)) {
            return null;
        }

        $new = is_array($change['new'] ?? null) ? $change['new'] : null;
        $old = is_array($change['old'] ?? null) ? $change['old'] : null;

        // A deletion has no `new`; the ref it removed is described by `old`.
        $ref = $new ?? $old ?? [];
        $name = $ref['name'] ?? null;
        $type = $ref['type'] ?? null;
        if (!is_string($name) || $name === '' || !is_string($type)) {
            return null;
        }

        $deleted = $new === null;
        $hash = $new['target']['hash'] ?? null;
        $commit = !$deleted && is_string($hash) ? $hash : null;

        if (in_array($type, ['tag', 'annotated_tag'], true)) {
            return new RefChange(ref: 'refs/tags/' . $name, tag: true, deleted: $deleted, commit: $commit);
        }

        if ($type !== 'branch') {
            return new RefChange(ref: $name, deleted: $deleted);
        }

        return new RefChange(ref: 'refs/heads/' . $name, branch: $name, deleted: $deleted, commit: $commit);
    }
}
