<?php

namespace App\Lib\DeployHook;

/**
 * What a provider's request says happened, in the words the engine acts on.
 *
 * Providers disagree on everything about how a push is spelled -- header
 * names, signature scheme, payload shape -- and agree on nothing else, so a
 * provider's job ends at producing one of these. The decision of whether a
 * push deploys is made from it and never sees a provider's payload.
 */
final class HookEvent
{
    /** A liveness check the provider sends when a hook is registered. */
    public const PING = 'ping';

    /** A push of a branch or a tag. */
    public const PUSH = 'push';

    /** Anything else the provider can be told to send. Recorded, never acted on. */
    public const OTHER = 'other';

    /**
     * Every ref the push moved. A push that names one ref (GitHub, GitLab)
     * fills the flat `ref`..`commit` fields below and gets a single change out
     * of them; one that names several (Bitbucket) is built with {@see push()},
     * and the flat fields then describe its first change.
     *
     * @var list<RefChange>
     */
    public readonly array $changes;

    /**
     * @param string           $kind       PING, PUSH or OTHER
     * @param string           $event      the provider's own name for it (`push`, `ping`, `issues`)
     * @param ?string          $deliveryId the provider's id for this delivery, the same on a redelivery
     * @param ?string          $ref        the full ref (`refs/heads/main`), pushes only
     * @param ?string          $branch     the branch name, when the ref is one -- also for a deletion
     * @param bool             $tag        the ref is a tag
     * @param bool             $deleted    the push removed the ref
     * @param ?string          $commit     the commit the ref points at now
     * @param list<RefChange>  $changes    the refs a multi-ref push moved; leave empty for a single ref
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $kind,
        public readonly string $event,
        public readonly ?string $deliveryId = null,
        public readonly ?string $ref = null,
        public readonly ?string $branch = null,
        public readonly bool $tag = false,
        public readonly bool $deleted = false,
        public readonly ?string $commit = null,
        array $changes = [],
    ) {
        $this->changes = $changes !== []
            ? array_values($changes)
            : ($ref !== null ? [new RefChange($ref, $branch, $tag, $deleted, $commit)] : []);
    }

    /**
     * A push that carries a list of ref changes, possibly none.
     *
     * @param list<RefChange> $changes
     */
    public static function push(string $provider, string $event, ?string $deliveryId, array $changes): self
    {
        $first = $changes[0] ?? null;

        return new self(
            provider: $provider,
            kind: self::PUSH,
            event: $event,
            deliveryId: $deliveryId,
            ref: $first?->ref,
            branch: $first?->branch,
            tag: $first?->tag ?? false,
            deleted: $first?->deleted ?? false,
            commit: $first?->commit,
            changes: $changes,
        );
    }
}
