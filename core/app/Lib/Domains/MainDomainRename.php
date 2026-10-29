<?php

namespace App\Lib\Domains;

use App\Exceptions\ProblemException;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Moving a project's main domain to a new name, as `PUT /projects/{project}`
 * with `domain` does it: a new domain row carrying the old one's settings
 * and aliases, the proxy rules retargeted, then the old one removed.
 */
final class MainDomainRename
{
    private const PROXY_ZONE_REFUSAL = 'Names under '.DomainPlan::PANELALPHA_ONLINE.' are registered with PanelAlpha Online '
        .'and served through its proxy, so a main domain is not renamed to one. Add the name as a tunnel instead.';

    /**
     * Point the project's main domain at $newFqdn. The same name in any case
     * is left alone, so its vhost is not torn down and rebuilt for nothing.
     * The caller saves the user.
     *
     * @throws ProblemException domain_taken, when another project took the name since it was validated
     */
    public static function apply(User $user, string $newFqdn): void
    {
        $oldFqdn = self::currentName($user, $user->getMainDomain());
        $newFqdn = trim($newFqdn);

        if (self::isSameName($oldFqdn, $newFqdn)) {
            return;
        }

        $domain = $user->getMainDomain() ?? self::placeholder($user);
        $newDomain = self::replacement($domain, $newFqdn);

        $previous = $user->getOriginal('domain');
        $routing = self::routingOf($user, $oldFqdn);
        self::claimName($user, $newFqdn);

        try {
            self::moveDomain($user, $domain, $newDomain, $oldFqdn, $newFqdn);
        } catch (\Throwable $e) {
            self::restoreRouting($user, $routing, $oldFqdn, $newFqdn);
            self::writeNameBack($user, $previous, $newFqdn);

            throw $e;
        }
        $user->domain = $newFqdn;

        $user->setDetails(['domain' => self::allocationFor($newFqdn)]);
    }

    /**
     * Retargets the proxy rules before the new vhost is rendered: DinD routing is driven only by
     * ProxyRule rows (no app_port fallback), and Domain::delete() would drop rules keyed by the old name.
     */
    private static function moveDomain(User $user, Domain $domain, Domain $newDomain, string $oldFqdn, string $newFqdn): void
    {
        $oldNameTunnels = self::oldNameTunnels($domain, $oldFqdn);
        $retargeted = $oldFqdn === '' ? 0 : ProxyRule::retargetServerName($user->username, $oldFqdn, $newFqdn);
        if ($retargeted === 0) {
            self::generateDindRouting($user, $newFqdn);
        }

        $newDomain->projectDomain()->create();
        self::deleteVhostIgnoringFailure($domain);
        $domain->delete();
        $newDomain->save();
        self::releaseTunnels($oldNameTunnels);
    }

    private static function generateDindRouting(User $user, string $newFqdn): void
    {
        $appPort = $user->getTemplate() === 'dind' ? $user->getAppPort() : null;
        if ($appPort !== null) {
            ProxyRule::ensureGeneratedHttpPair($user->username, $newFqdn, $appPort, $user->getAppPortScheme());
        }
    }

    private static function deleteVhostIgnoringFailure(Domain $domain): void
    {
        try {
            $domain->projectDomain()->delete();
        } catch (\Exception) {
        }
    }

    /**
     * Claims the name before any proxy rule or vhost is touched, so a name another project
     * took since validation is refused by the unique index before anything was rewritten.
     *
     * @throws ProblemException domain_taken
     */
    private static function claimName(User $user, string $newFqdn): void
    {
        try {
            User::query()->whereKey($user->getKey())->update(['domain' => $newFqdn]);
        } catch (UniqueConstraintViolationException) {
            throw ProblemException::one('domain', 'domain_taken', "{$newFqdn} is already on this engine.");
        }
    }

    /**
     * The raw attributes of the old name's proxy rules, read before anything changes so a failed
     * rename can put them back: a failing create() of the new domain deletes the rules moved to it.
     *
     * @return list<array<string, mixed>>
     */
    private static function routingOf(User $user, string $oldFqdn): array
    {
        if ($oldFqdn === '') {
            return [];
        }

        return ProxyRule::forUser($user->username)
            ->whereIn('server_name', [$oldFqdn, 'www.'.$oldFqdn])
            ->get()
            ->map(static fn (ProxyRule $rule): array => $rule->getAttributes())
            ->all();
    }

    /**
     * Raw attributes go back raw: create() would re-encode the already-encoded metadata JSON.
     * A failure is logged, never thrown, so it does not replace the error that failed the rename.
     *
     * @param  list<array<string, mixed>>  $routing
     */
    private static function restoreRouting(User $user, array $routing, string $oldFqdn, string $newFqdn): void
    {
        try {
            if ($oldFqdn !== '' && strcasecmp($oldFqdn, $newFqdn) !== 0) {
                ProxyRule::forUser($user->username)
                    ->whereIn('server_name', [$newFqdn, 'www.'.$newFqdn])
                    ->delete();
                foreach ($routing as $attributes) {
                    unset($attributes['id']);
                    ProxyRule::query()->insert($attributes);
                }
            }
        } catch (\Throwable $restore) {
            Log::error(
                "Restoring proxy rules after a failed rename of {$oldFqdn} to {$newFqdn} failed: ".$restore->getMessage()
            );
        }
    }

    /** A failed write-back is logged, without replacing the error that made the rename fail. */
    private static function writeNameBack(User $user, mixed $previous, string $newFqdn): void
    {
        try {
            User::query()->whereKey($user->getKey())->update(['domain' => $previous]);
        } catch (\Throwable $writeBack) {
            Log::error(
                "Writing the domain {$previous} back after a failed rename to {$newFqdn} failed: ".$writeBack->getMessage()
            );
        }
    }

    /**
     * The `details.domain` block a rename to $newFqdn leaves behind: what the
     * allocator records for a name the caller chose, replacing the old name's.
     *
     * @return array<string, mixed>
     */
    public static function allocationFor(string $newFqdn): array
    {
        return (new AllocatedDomain(
            domain: $newFqdn,
            source: DomainPlan::SOURCE_REQUESTED,
            publiclyResolvable: null,
            tlsTerminatedAt: 'engine',
        ))->toDetails();
    }

    /**
     * Why the main domain cannot be renamed to $newFqdn, or null when it can.
     *
     * @return ?array{code: string, message: string}
     */
    public static function refusal(User $user, string $newFqdn): ?array
    {
        $domain = $user->getMainDomain();
        $oldFqdn = self::currentName($user, $domain);
        if (self::isSameName($oldFqdn, trim($newFqdn))) {
            return null;
        }
        if (($taken = self::takenName($user, $newFqdn)) !== null) {
            return ['code' => 'domain_taken', 'message' => "{$taken} is already on this engine."];
        }
        if (DomainPlan::inOnlineZone($newFqdn)) {
            return ['code' => 'domain_proxy_zone', 'message' => self::PROXY_ZONE_REFUSAL];
        }

        return self::tunnelRefusal($domain, $oldFqdn);
    }

    /**
     * Tunnels other than the one serving the old name forward to the domain under its current name;
     * the rename would drop them while the provider kept them, so they are deleted first.
     *
     * @return ?array{code: string, message: string}
     */
    private static function tunnelRefusal(?Domain $domain, string $oldFqdn): ?array
    {
        $forwarding = array_map(static fn (Tunnel $tunnel): string => $tunnel->hostname, self::forwardingTunnels($domain, $oldFqdn));
        if ($forwarding === []) {
            return null;
        }

        return [
            'code' => 'domain_has_tunnels',
            'message' => "{$oldFqdn} has tunnels that forward to it (".implode(', ', $forwarding).'); delete them before renaming it.',
        ];
    }

    /**
     * The domain's PanelAlpha Online tunnel that is its old name itself.
     *
     * @return list<Tunnel>
     */
    private static function oldNameTunnels(?Domain $domain, string $oldFqdn): array
    {
        return array_values(array_filter(self::tunnelsOf($domain), static fn (Tunnel $tunnel): bool => self::isOldName($tunnel, $oldFqdn)));
    }

    /** @return list<Tunnel> */
    private static function forwardingTunnels(?Domain $domain, string $oldFqdn): array
    {
        return array_values(array_filter(self::tunnelsOf($domain), static fn (Tunnel $tunnel): bool => ! self::isOldName($tunnel, $oldFqdn)));
    }

    /** @return list<Tunnel> */
    private static function tunnelsOf(?Domain $domain): array
    {
        return $domain !== null && $domain->exists ? Tunnel::forDomain($domain) : [];
    }

    private static function isOldName(Tunnel $tunnel, string $oldFqdn): bool
    {
        return PanelAlphaConnect::servesItsOwnPublicName($oldFqdn, $tunnel->hostname, $tunnel->provider);
    }

    /**
     * The old name is no longer served here, so its PanelAlpha Online site is deleted
     * rather than left registered for a name this engine forgot.
     *
     * @param  list<Tunnel>  $tunnels
     */
    private static function releaseTunnels(array $tunnels): void
    {
        foreach ($tunnels as $tunnel) {
            self::deleteSiteIgnoringFailure($tunnel);
            $tunnel->delete();
        }
    }

    /** The rename has gone through by now, so a proxy that cannot be reached is logged rather than undoing it. */
    private static function deleteSiteIgnoringFailure(Tunnel $tunnel): void
    {
        try {
            PanelAlphaConnect::deleteTunnelRemote($tunnel);
        } catch (\Throwable $e) {
            Log::warning("Could not delete the PanelAlpha Online site {$tunnel->hostname} after a rename: ".$e->getMessage());
        }
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
        $oldFqdn = self::currentName($user, $domain);
        $newFqdn = trim($newFqdn);

        if (self::isSameName($oldFqdn, $newFqdn)) {
            return null;
        }
        if (self::heldElsewhere($user, $domain, $newFqdn)) {
            return $newFqdn;
        }

        $www = 'www.'.$newFqdn;
        if (
            $domain !== null
            && in_array('www.'.$domain->domain, $domain->getAliases())
            && self::heldElsewhere($user, $domain, $www)
        ) {
            return $www;
        }

        return null;
    }

    private static function currentName(User $user, ?Domain $mainDomain): string
    {
        return trim((string) ($mainDomain?->domain ?? $user->domain ?? ''));
    }

    /** The same name in any case: renaming to it would rebuild the vhost for nothing. */
    private static function isSameName(string $oldFqdn, string $newFqdn): bool
    {
        return $oldFqdn !== '' && strcasecmp($oldFqdn, $newFqdn) === 0;
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
        foreach ($domain->getAliases() as $alias) {
            self::carryAlias($domain, $newDomain, $alias, 'www.'.$newFqdn);
        }
        $newDomain->setDetails(['aliases' => array_values(array_unique($newDomain->getAliases()))]);

        return $newDomain;
    }

    /**
     * Moves one alias onto the renamed row, the old `www.` alias becoming $newWww. It is set
     * directly, not with addAlias(): the old row still holds it, so the availability check would find it taken.
     */
    private static function carryAlias(Domain $domain, Domain $newDomain, mixed $alias, string $newWww): void
    {
        if ($alias == 'www.'.$domain->domain) {
            if (! self::servedBy($domain, $newWww)) {
                $newDomain->addAlias($newWww);

                return;
            }
            $alias = $newWww;
        }
        $newDomain->setDetails(['aliases' => [...$newDomain->getAliases(), $alias]]);
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
