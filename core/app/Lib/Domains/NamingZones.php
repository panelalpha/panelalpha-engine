<?php

namespace App\Lib\Domains;

use App\Lib\Ssl\SharedZones;
use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The parents the engine names more than one project under: the fleet's {@see SharedZones} and this engine's
 * own bases. A browser treats every name below one as the same site, so their pages can share cookies.
 */
final class NamingZones
{
    private const string HOSTNAME = '/^(?:[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** Where the allocator recorded a generated name, the base it was named under. */
    private const array GENERATED_SOURCES = [
        DomainPlan::SOURCE_SITES_BASE_DOMAIN,
        DomainPlan::SOURCE_PANELALPHA_DIRECT,
    ];

    /**
     * @param  list<string>  $zones
     */
    private function __construct(private readonly array $zones) {}

    /**
     * The fleet's zones, the configured bases, and every base a project's main domain was
     * generated under, which stays shared after the setting moves on.
     */
    public static function current(): self
    {
        return self::of([
            ...SharedZones::ZONES,
            ...array_map(static fn (string $name): string => (string) Setting::get($name), NamingBaseSetting::NAMES),
            ...self::generatedBases(),
        ]);
    }

    /**
     * Single labels and addresses are dropped: a zone that broad would cover every name.
     *
     * @param  list<string>  $candidates
     */
    public static function of(array $candidates): self
    {
        $zones = array_values(array_unique(array_filter(array_map(self::normalise(...), $candidates))));

        return new self(array_values(array_filter(
            $zones,
            static fn (string $zone): bool => ! self::isBelowAny($zone, $zones)
        )));
    }

    /**
     * A dotted hostname lowercased without its trailing dot, or null for anything else.
     */
    public static function normalise(string $name): ?string
    {
        $name = strtolower(trim($name, " \t\n\r\0\x0B."));

        return preg_match(self::HOSTNAME, $name) === 1 && filter_var($name, FILTER_VALIDATE_IP) === false ? $name : null;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->zones;
    }

    /** Whether $name is a zone or a name below one. */
    public function covers(string $name): bool
    {
        foreach ($this->zones as $zone) {
            if ($name === $zone || str_ends_with($name, '.'.$zone)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a zone lies below $name, so a cookie for $name reaches every name in it. */
    public function isAboveAZone(string $name): bool
    {
        foreach ($this->zones as $zone) {
            if (str_ends_with($zone, '.'.$name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parents of $names, of two labels or more, that a cookie must not be written for:
     * a zone, a name below one or a parent of one, none of them $own.
     *
     * @param  list<string>  $names
     * @param  list<string>  $own
     * @return list<string>
     */
    public function sharedParentsOf(array $names, array $own): array
    {
        $shared = [];
        foreach ($names as $name) {
            foreach (self::parents($name) as $parent) {
                if (! in_array($parent, $own, true) && ($this->covers($parent) || $this->isAboveAZone($parent))) {
                    $shared[] = $parent;
                }
            }
        }

        return array_values(array_unique($shared));
    }

    /**
     * @return list<string> the parents of $name with two labels or more, nearest first
     */
    private static function parents(string $name): array
    {
        $parents = [];
        while (substr_count($name, '.') > 1) {
            $name = Str::after($name, '.');
            $parents[] = $name;
        }

        return $parents;
    }

    /**
     * @param  list<string>  $zones
     */
    private static function isBelowAny(string $name, array $zones): bool
    {
        foreach ($zones as $zone) {
            if (str_ends_with($name, '.'.$zone)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function generatedBases(): array
    {
        /** @var list<string> */
        return Domain::query()
            ->where('type', 'main')
            ->whereHas('user', static fn (Builder $user) => $user->whereIn('details->domain->source', self::GENERATED_SOURCES))
            ->pluck('domain')
            ->map(static fn (string $domain): string => Str::after($domain, '.'))
            ->all();
    }
}
