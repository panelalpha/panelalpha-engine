<?php

namespace App\Lib\Project\PublicRouting;

use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Domains\MainDomainRename;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;

/**
 * The server's own name (`cert_domain`), which only a project the operator allowed may hold. `domain: host` makes it
 * the main domain once, at the first deploy that may; only the operator takes the name away.
 *
 * @psalm-type DomainState = array{state: string, name: ?string, reason?: string, applied?: bool, previous?: array{name: string, allocation: mixed}}
 */
class HostDomain
{
    public const ON_HOST = 'host';

    public const GENERATED = 'generated';

    public const REFUSED = 'refused';

    public const NOT_ALLOWED = 'not_allowed';

    /** The code a refused claim of the server's name answers with. */
    public const CLAIM_REFUSED = 'host_domain_not_allowed';

    private const NOT_ALLOWED_REASON = 'the operator has not allowed this project the server\'s own name';

    public function __construct(
        private readonly PublicRoutingGrants $grants,
        private readonly DomainAllocator $allocator,
    ) {}

    /** The server's own name, or null on an engine known only by its address. */
    public static function name(): ?string
    {
        $name = strtolower(trim((string) Setting::get('cert_domain'), " \t\n\r\0\x0B."));

        return $name === '' ? null : $name;
    }

    /**
     * Why the project may not take $name, or null: the server's own name needs the operator's `host_domain` permission.
     *
     * @return ?array{code: string, message: string}
     */
    public static function claimRefusal(string $username, string $name): ?array
    {
        $host = self::name();
        if ($host === null || strcasecmp(trim($name, " \t\n\r\0\x0B."), $host) !== 0) {
            return null;
        }
        if ((new PublicRoutingGrants)->allows($username, PublicRoutingGrants::HOST_DOMAIN)) {
            return null;
        }

        return [
            'code' => self::CLAIM_REFUSED,
            'message' => "{$host} is this server's own name, and the operator has not allowed this project to use it.",
        ];
    }

    /**
     * Moves the main domain where $routing asks, the first time it may, and saves the user; what came of it.
     *
     * @param  ?array<string, mixed>  $previous  the {@see DomainState} the last call returned
     * @return DomainState
     */
    public function apply(User $user, PublicRouting $routing, ?array $previous): array
    {
        $kept = self::kept($previous);
        $applied = self::wasApplied($previous);
        $host = self::name();
        $allowed = $this->grants->allows($user->username, PublicRoutingGrants::HOST_DOMAIN);
        if ($host !== null && ! $allowed && self::holds($user, $host)) {
            return $this->leave($user, $kept, self::NOT_ALLOWED, self::NOT_ALLOWED_REASON, $applied);
        }
        if ($applied) {
            return self::state($host !== null && self::isOn($user, $host) ? self::ON_HOST : self::GENERATED, $user, $kept, null, true);
        }
        if (! $routing->wantsHostDomain()) {
            return self::state(self::GENERATED, $user, $kept);
        }
        if (! $allowed) {
            return self::state(self::NOT_ALLOWED, $user, $kept, self::NOT_ALLOWED_REASON);
        }
        if ($host === null) {
            return self::state(self::REFUSED, $user, $kept, 'the engine has no name of its own: cert_domain is not set');
        }
        if (self::isOn($user, $host)) {
            return self::state(self::ON_HOST, $user, $kept, null, true);
        }

        $refusal = MainDomainRename::refusal($user, $host);
        if ($refusal !== null) {
            return self::state(self::REFUSED, $user, $kept, $refusal['message']);
        }

        $kept ??= ['name' => (string) $user->domain, 'allocation' => $user->getDetails()['domain'] ?? null];
        MainDomainRename::apply($user, $host);
        $user->save();

        return self::state(self::ON_HOST, $user, $kept, null, true);
    }

    /**
     * Off the server's name, wherever the project holds it: the main domain back on the name it had when that is
     * still free, else a fresh generated one; an addon or alias of that name removed.
     *
     * @param  ?array<string, mixed>  $previous  the {@see DomainState} the last call returned
     * @return DomainState
     */
    public function release(User $user, ?array $previous, string $state = self::NOT_ALLOWED, ?string $reason = null): array
    {
        return $this->leave($user, self::kept($previous), $state, $reason, self::wasApplied($previous));
    }

    /**
     * The last recorded state corrected by the project's current domains, which may have changed since.
     *
     * @param  ?array<string, mixed>  $recorded
     * @return ?array<string, mixed>
     */
    public static function current(User $user, ?array $recorded): ?array
    {
        if ($recorded === null) {
            return null;
        }
        $host = self::name();
        $onHost = $host !== null && self::isOn($user, $host);
        if ($onHost === (($recorded['state'] ?? null) === self::ON_HOST)) {
            return ['name' => $user->domain] + $recorded;
        }

        return array_diff_key(['state' => $onHost ? self::ON_HOST : self::GENERATED, 'name' => $user->domain] + $recorded, ['reason' => true]);
    }

    /**
     * @param  ?array{name: string, allocation: mixed}  $kept
     * @return DomainState
     */
    private function leave(User $user, ?array $kept, string $state, ?string $reason, bool $applied): array
    {
        $host = self::name();
        if ($host !== null) {
            self::dropAddons($user, $host);
            if (self::isOn($user, $host)) {
                $this->renameBack($user, $kept);
            }
        }

        return self::state($state, $user, null, $reason, $applied);
    }

    /** @param ?array{name: string, allocation: mixed} $kept */
    private function renameBack(User $user, ?array $kept): void
    {
        $name = $kept['name'] ?? '';
        $allocation = $kept['allocation'] ?? null;
        if ($name === '' || MainDomainRename::refusal($user, $name) !== null) {
            $name = $this->allocator->allocate($user->username, null, DomainPlan::TUNNEL_NONE)->domain;
            $allocation = null;
        }
        MainDomainRename::apply($user, $name);
        if (is_array($allocation)) {
            $user->setDetails(['domain' => $allocation]);
        }
        $user->save();
    }

    /** The project's addon or subdomain of that name deleted, and the name taken off any alias list. */
    private static function dropAddons(User $user, string $host): void
    {
        foreach ($user->domains()->getQuery()->get() as $domain) {
            /** @var Domain $domain */
            if ($domain->type !== 'main' && strcasecmp((string) $domain->domain, $host) === 0) {
                $closesPorts = HttpsPorts::hasExtra($domain);
                $domain->projectDomain()->delete();
                $domain->delete();
                if ($closesPorts) {
                    app(HttpsPortsChange::class)->listenersChanged();
                }

                continue;
            }
            $aliases = $domain->getAliases();
            $kept = array_values(array_filter($aliases, static fn (string $alias): bool => strcasecmp($alias, $host) !== 0));
            if ($kept !== $aliases) {
                $domain->setDetails(['aliases' => $kept]);
                $domain->save();
                $domain->projectDomain()->rebuild();
            }
        }
    }

    private static function holds(User $user, string $host): bool
    {
        if (self::isOn($user, $host)) {
            return true;
        }
        foreach ($user->domains()->getQuery()->get() as $domain) {
            /** @var Domain $domain */
            if (strcasecmp((string) $domain->domain, $host) === 0 || in_array($host, array_map('strtolower', $domain->getAliases()), true)) {
                return true;
            }
        }

        return false;
    }

    private static function isOn(User $user, string $host): bool
    {
        return strcasecmp(trim((string) $user->domain), $host) === 0;
    }

    /** @param ?array<string, mixed> $previous */
    private static function wasApplied(?array $previous): bool
    {
        return ($previous['applied'] ?? false) === true || ($previous['state'] ?? null) === self::ON_HOST;
    }

    /**
     * @param  ?array<string, mixed>  $previous
     * @return ?array{name: string, allocation: mixed}
     */
    private static function kept(?array $previous): ?array
    {
        $kept = $previous['previous'] ?? null;

        return is_array($kept) && is_string($kept['name'] ?? null) ? ['name' => $kept['name'], 'allocation' => $kept['allocation'] ?? null] : null;
    }

    /**
     * @param  ?array{name: string, allocation: mixed}  $kept
     * @return DomainState
     */
    private static function state(string $state, User $user, ?array $kept, ?string $reason = null, bool $applied = false): array
    {
        return ['state' => $state, 'name' => $user->domain]
            + ($reason === null ? [] : ['reason' => $reason])
            + ($applied ? ['applied' => true] : [])
            + ($kept === null ? [] : ['previous' => $kept]);
    }
}
