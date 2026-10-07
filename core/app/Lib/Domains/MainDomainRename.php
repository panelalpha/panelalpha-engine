<?php

namespace App\Lib\Domains;

use App\Exceptions\ProblemException;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

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
     *
     * @throws ProblemException domain_taken, when another project took the name since it was validated
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

        // Claimed before any proxy rule or vhost is touched: a name another
        // project took since validation is refused by the unique index here,
        // not after this project's vhosts have been rewritten.
        $previous = $user->getOriginal('domain');
        try {
            User::query()->whereKey($user->getKey())->update(['domain' => $newFqdn]);
        } catch (UniqueConstraintViolationException) {
            throw ProblemException::one('domain', 'domain_taken', "{$newFqdn} is already on this engine.");
        }

        try {
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
                ProxyRule::ensureGeneratedHttpPair($user->username, $newFqdn, $appPort, $user->getAppPortScheme());
            }

            $newDomain->projectDomain()->create();
            try {
                $domain->projectDomain()->delete();
            } catch (\Exception $e) {
            }
            $domain->delete();
            $newDomain->save();
        } catch (\Throwable $e) {
            User::query()->whereKey($user->getKey())->update(['domain' => $previous]);
            throw $e;
        }
        $user->domain = $newFqdn;
    }

    /**
     * The first name a rename to $newFqdn would take that is already on this
     * engine -- the name, then the `www.` alias that moves with it -- or null
     * when both are free. What the main domain row itself holds (its name,
     * aliases and tunnels) goes with it and does not count; the project's
     * other domains do, as two rows for one name share one vhost file.
     */
    public static function takenName(User $user, string $newFqdn): ?string
    {
        $domain = $user->getMainDomain();
        $oldFqdn = trim((string) ($domain?->domain ?? $user->domain ?? ''));
        $newFqdn = trim($newFqdn);

        if ($oldFqdn !== '' && strcasecmp($oldFqdn, $newFqdn) === 0) {
            return null;
        }
        if (self::heldElsewhere($user, $domain, $newFqdn)) {
            return $newFqdn;
        }

        $www = 'www.' . $newFqdn;
        if (
            $domain !== null
            && in_array('www.' . $domain->domain, $domain->getAliases())
            && self::heldElsewhere($user, $domain, $www)
        ) {
            return $www;
        }

        return null;
    }

    /**
     * Another project's `users.domain`, any domain row but $own (by name or
     * alias), or a tunnel not attached to $own.
     */
    private static function heldElsewhere(User $user, ?Domain $own, string $name): bool
    {
        $rows = Domain::query()->where(static function (Builder $query) use ($name): void {
            $query->where('domain', $name)->orWhereJsonContains('details->aliases', $name);
        });
        $tunnels = Tunnel::query()->where('hostname', strtolower($name));
        if ($own !== null && $own->exists) {
            $rows->whereKeyNot($own->getKey());
            $tunnels->where('domain_id', '!=', $own->getKey());
        }

        return User::query()->where('domain', $name)->whereKeyNot($user->getKey())->exists()
            || $rows->exists()
            || $tunnels->exists();
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
        $www = 'www.' . $newFqdn;
        foreach ($domain->getAliases() as $alias) {
            if ($alias == 'www.' . $domain->domain) {
                if (!self::servedBy($domain, $www)) {
                    $newDomain->addAlias($www);
                    continue;
                }
                // Served by this row already: it goes with it, like its other aliases.
                $alias = $www;
            }
            // Not addAlias(): the old row, this same domain, still holds it, so
            // the availability check would find it taken.
            $newDomain->setDetails(['aliases' => [...$newDomain->getAliases(), $alias]]);
        }
        $newDomain->setDetails(['aliases' => array_values(array_unique($newDomain->getAliases()))]);

        return $newDomain;
    }

    /** The row already serves $name, as an alias or a tunnel: it goes with the row. */
    private static function servedBy(Domain $domain, string $name): bool
    {
        return in_array($name, $domain->getAliases())
            || ($domain->exists && $domain->tunnels()->getQuery()->where('hostname', strtolower($name))->exists());
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
