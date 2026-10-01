<?php

namespace App\Lib\Domains;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;

/**
 * Moving a project's main domain to a new name, as `PUT /projects/{project}`
 * with `domain` does it: a new domain row carrying the old one's settings
 * and aliases, the proxy rules retargeted, then the old one removed.
 */
final class MainDomainRename
{
    /**
     * Point the project's main domain at $newFqdn. The same name in any case
     * is left alone, so its vhost is not torn down and rebuilt for nothing.
     * The caller saves the user.
     */
    public static function apply(User $user, string $newFqdn): void
    {
        $oldFqdn = trim((string) ($user->getMainDomain()?->domain ?? $user->domain ?? ''));
        $newFqdn = trim($newFqdn);

        if ($oldFqdn !== '' && strcasecmp($oldFqdn, $newFqdn) === 0) {
            return;
        }

        $domain = $user->getMainDomain() ?? self::placeholder($user);
        $newDomain = self::replacement($domain, $newFqdn);

        // Retarget proxy rules before rendering the new vhost: DinD
        // routing is driven only by ProxyRule rows (no app_port fallback).
        // Domain::delete() would otherwise drop rules keyed by the old name.
        $retargeted = 0;
        if ($oldFqdn !== '') {
            $retargeted = ProxyRule::retargetServerName($user->username, $oldFqdn, $newFqdn);
        }
        if (
            $retargeted === 0
            && $user->getTemplate() === 'dind'
            && ($appPort = $user->getAppPort()) !== null
        ) {
            ProxyRule::ensureGeneratedHttpPair($user->username, $newFqdn, $appPort);
        }

        $newDomain->projectDomain()->create();
        try {
            $domain->projectDomain()->delete();
        } catch (\Exception $e) {
        }
        $domain->delete();
        $newDomain->save();
        $user->domain = $newFqdn;
    }

    /**
     * The main domain under its new name: the old one's settings, with its
     * `www.` alias following the name and every other alias kept.
     */
    public static function replacement(Domain $domain, string $newFqdn): Domain
    {
        $newDomain = $domain->replicate();
        $newDomain->domain = $newFqdn;
        $newDomain->removeAliases();
        foreach ($domain->getAliases() as $alias) {
            if ($alias == 'www.' . $domain->domain) {
                $newDomain->addAlias('www.' . $newFqdn);
                continue;
            }
            // Not addAlias(): the old row, this same domain, still holds it, so
            // the availability check would find it taken.
            $newDomain->setDetails(['aliases' => [...$newDomain->getAliases(), $alias]]);
        }

        return $newDomain;
    }

    /** A project with no main domain row gets one, unsaved, to rename from. */
    private static function placeholder(User $user): Domain
    {
        /** @var Domain */
        return Domain::make([
            'user_id' => $user->id,
            'domain' => $user->domain,
            'type' => 'main',
            'details' => [
                'document_root' => "/{$user->domain}/public_html",
                'redirect_enabled' => false,
                'redirect_url' => null,
                'force_https_redirect' => false,
            ],
        ]);
    }
}
