<?php

namespace App\System\Services\Webserver;

use App\Models\ProxyRule;

/**
 * A project's rule reaches its own account only, by the container's name: any other name
 * or address is another project, an engine service or the host. System rules are not limited.
 */
final class ProxyRuleUpstream
{
    /** Why this owner may not use the upstream, or null when it may. */
    public static function refusal(?string $ownerScope, ?string $username, string $upstreamHost): ?string
    {
        if ($ownerScope === 'system' || ($username !== null && $username !== '' && $upstreamHost === $username)) {
            return null;
        }
        if ($username === null || $username === '') {
            return "A project's rule needs its project.";
        }

        return "The upstream must be the project's own app, '{$username}': "
            . "a project's rule cannot reach another project, the engine's services or the host.";
    }

    /** A rule stored before upstreams were checked is never served. */
    public static function serves(ProxyRule $rule): bool
    {
        return self::refusal($rule->owner_scope, $rule->username, $rule->upstream_host) === null;
    }
}
