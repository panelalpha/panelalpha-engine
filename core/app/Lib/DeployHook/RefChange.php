<?php

namespace App\Lib\DeployHook;

/**
 * One ref a push moved, in the words the engine acts on.
 *
 * GitHub and GitLab send one of these per request. Bitbucket sends a list --
 * one `git push main feature v1` is a single delivery naming three refs -- so
 * the decision of whether a delivery deploys is made per change.
 */
final class RefChange
{
    /**
     * @param string  $ref     the full ref (`refs/heads/main`)
     * @param ?string $branch  the branch name, when the ref is one -- also for a deletion
     * @param bool    $tag     the ref is a tag
     * @param bool    $deleted the push removed the ref
     * @param ?string $commit  the commit the ref points at now
     */
    public function __construct(
        public readonly string $ref,
        public readonly ?string $branch = null,
        public readonly bool $tag = false,
        public readonly bool $deleted = false,
        public readonly ?string $commit = null,
    ) {
    }
}
