<?php

namespace App\Lib\Domains;

use App\Lib\Project\PublicRouting\HostDomain;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One of a container project's addon domains made its main one. The old main domain stays as an addon and both keep
 * answering; what changes is the name the app is told it lives at, which the caller's redeploy applies.
 */
final class MainDomainSwitch
{
    public const CONTAINER_ONLY = 'main_domain_switch_container_only';

    public const NOT_ADDON = 'main_domain_switch_not_addon';

    /**
     * Why $domain cannot become the main domain, or null when it can or already is.
     *
     * @return ?array{code: string, message: string}
     */
    public static function refusal(User $user, Domain $domain): ?array
    {
        if ($domain->type === 'main') {
            return null;
        }
        if ($user->getTemplate() !== 'dind') {
            return ['code' => self::CONTAINER_ONLY, 'message' => 'Only a container project\'s main domain moves to another of its domains; rename the main domain instead.'];
        }
        if ($domain->type !== 'addon') {
            return ['code' => self::NOT_ADDON, 'message' => "{$domain->domain} is a subdomain: add the name as an addon domain to make it the main one."];
        }

        return HostDomain::claimRefusal((string) $user->username, (string) $domain->domain);
    }

    public static function apply(User $user, Domain $domain): void
    {
        $old = $user->getMainDomain();
        DB::transaction(static function () use ($user, $domain, $old): void {
            if ($old !== null) {
                $old->type = 'addon';
                $old->save();
            }
            $domain->type = 'main';
            $domain->save();
            $user->domain = $domain->domain;
            $user->setDetails(['domain' => MainDomainRename::allocationFor((string) $domain->domain)]);
            $user->save();
        });
        self::routeToApp($user, $domain);
    }

    /** A domain added before every domain was routed has no rules of its own yet. */
    private static function routeToApp(User $user, Domain $domain): void
    {
        $upstream = ProxyRule::generatedUpstreamOf((string) $user->username);
        if ($upstream === null) {
            return;
        }
        ProxyRule::ensureGeneratedHttpPair((string) $user->username, (string) $domain->domain, $upstream['port'], $upstream['protocol']);
        $domain->projectDomain()->rebuild();
    }
}
