<?php

namespace App\Lib\Domains;

use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The hostnames a project answers to on this engine: its domains, their
 * aliases and its tunnel hostnames.
 */
class ProjectHostnames
{
    /**
     * @return list<string>
     */
    public static function of(User $user): array
    {
        return self::named(
            Domain::query()->where('user_id', $user->id),
            Tunnel::query()->where('user_id', $user->id)
        )->all();
    }

    /**
     * Whether a project answers to a hostname in the zone that is neither this one nor a
     * staging copy of the same site (its live project, or the live project's staging copy).
     */
    public static function zoneServesAnotherProject(User $user, string $zone): bool
    {
        $site = self::siteProjectIds($user);

        return self::named(
            Domain::query()->whereNotIn('user_id', $site),
            Tunnel::query()->whereNotIn('user_id', $site)
        )->contains(static fn (string $hostname): bool => self::isInZone($hostname, $zone));
    }

    /**
     * @return list<int>
     */
    private static function siteProjectIds(User $user): array
    {
        $live = $user->staging ?? $user->id;

        return User::query()
            ->where('id', $live)
            ->orWhere('staging', $live)
            ->orWhere('staging', $user->id)
            ->pluck('id')
            ->push($user->id)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private static function isInZone(string $hostname, string $zone): bool
    {
        $zone = strtolower($zone);

        return $hostname === $zone || str_ends_with($hostname, '.'.$zone);
    }

    /**
     * @param  Builder<Domain>  $domains
     * @param  Builder<Tunnel>  $tunnels
     * @return Collection<int, string>
     */
    private static function named(Builder $domains, Builder $tunnels): Collection
    {
        return $domains->get()
            ->flatMap(static fn (Domain $domain): array => [$domain->domain, ...$domain->getAliases()])
            ->merge($tunnels->pluck('hostname'))
            ->map(static fn (mixed $hostname): string => strtolower(trim((string) $hostname)))
            ->unique()
            ->values();
    }
}
