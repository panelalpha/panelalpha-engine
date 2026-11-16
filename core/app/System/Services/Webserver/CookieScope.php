<?php

namespace App\System\Services\Webserver;

use App\Lib\Domains\NamingZones;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;

/** What cookie isolation needs to know about one vhost, whichever webserver renders it. */
final readonly class CookieScope
{
    /**
     * @param  list<string>  $exposed  the vhost's names under a shared zone
     * @param  list<string>  $own  every name the project owns
     * @param  list<string>  $sharedParents  parents a Set-Cookie Domain must not name
     */
    public function __construct(
        public array $exposed,
        public array $own,
        public array $sharedParents,
        public bool $behindFront,
    ) {}

    public static function forDomain(Domain $domain, User $user, bool $behindFront): self
    {
        return self::of(
            NamingZones::current(),
            [$domain->domain, ...$domain->getVhostAltNames(), ...self::tunnelHostnames($domain)],
            self::projectNames($user),
            $behindFront,
        );
    }

    /**
     * @param  list<string>  $vhostNames  every name the vhost answers to, its tunnels' included
     * @param  list<string>  $projectNames  every name the project owns
     */
    public static function of(NamingZones $zones, array $vhostNames, array $projectNames, bool $behindFront): self
    {
        $vhostNames = self::hostnames($vhostNames);
        $own = self::hostnames([...$vhostNames, ...$projectNames]);

        return new self(
            array_values(array_filter($vhostNames, $zones->covers(...))),
            $own,
            $zones->sharedParentsOf($vhostNames, $own),
            $behindFront,
        );
    }

    /** Whether a neighbour's request must lose its cookies. */
    public function gated(): bool
    {
        return $this->exposed !== [] || $this->behindFront;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function hostnames(array $names): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $name): ?string => NamingZones::normalise($name),
            $names
        ))));
    }

    /**
     * @return list<string>
     */
    private static function tunnelHostnames(Domain $domain): array
    {
        if (! $domain->exists) {
            return [];
        }

        /** @var list<string> */
        return $domain->tunnels()->pluck('hostname')->all();
    }

    /**
     * @return list<string>
     */
    private static function projectNames(User $user): array
    {
        $names = [];
        foreach ($user->getDomains() as $domain) {
            $names = [...$names, $domain->domain, ...$domain->getAliases()];
        }

        /** @var list<string> $tunnels */
        $tunnels = Tunnel::query()->where('user_id', $user->id)->pluck('hostname')->all();

        return [...$names, ...$tunnels];
    }
}
