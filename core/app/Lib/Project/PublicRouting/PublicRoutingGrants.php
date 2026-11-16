<?php

namespace App\Lib\Project\PublicRouting;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * What the operator lets a project's `panelalpha.yaml` take: host ports, the server's own name, or both. An engine
 * setting, so no project token can grant itself anything; nothing is allowed until the operator says so.
 */
class PublicRoutingGrants
{
    public const SETTING = 'public_routing_projects';

    public const PORTS = 'ports';

    public const HOST_DOMAIN = 'host_domain';

    public const KINDS = [self::PORTS, self::HOST_DOMAIN];

    private const LOCK = 'public-routing-projects';

    private const LOCK_SECONDS = 5;

    /** @return list<string> */
    public function of(string $username): array
    {
        return $this->entries()[$username] ?? [];
    }

    public function allows(string $username, string $kind): bool
    {
        return in_array($kind, $this->of($username), true);
    }

    /**
     * @param  list<string>  $kinds
     * @return list<string> what the project is allowed now
     */
    public function allow(string $username, array $kinds): array
    {
        return $this->mutate($username, static fn (array $held): array => array_merge($held, $kinds));
    }

    /**
     * @param  list<string>  $kinds
     * @return list<string> what the project is allowed now
     */
    public function deny(string $username, array $kinds): array
    {
        return $this->mutate($username, static fn (array $held): array => array_diff($held, $kinds));
    }

    public function forget(string $username): void
    {
        $this->deny($username, self::KINDS);
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $entries = $this->entries();
        ksort($entries);

        return $entries;
    }

    /**
     * @param  callable(list<string>): array<array-key, string>  $change
     * @return list<string>
     */
    private function mutate(string $username, callable $change): array
    {
        return Cache::lock(self::LOCK, self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($username, $change): array {
            $entries = $this->entries();
            $kinds = self::normalised($change($entries[$username] ?? []));
            if ($kinds === []) {
                unset($entries[$username]);
            } else {
                $entries[$username] = $kinds;
            }
            $this->store($entries);

            return $kinds;
        });
    }

    /** @return array<string, list<string>> */
    private function entries(): array
    {
        $decoded = json_decode((string) Setting::get(self::SETTING), true);
        if (! is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $username => $kinds) {
            $kept = is_array($kinds) ? self::normalised($kinds) : [];
            if (is_string($username) && $kept !== []) {
                $entries[$username] = $kept;
            }
        }

        return $entries;
    }

    /** @param array<string, list<string>> $entries */
    private function store(array $entries): void
    {
        if ($entries === []) {
            Setting::forget(self::SETTING);

            return;
        }
        Setting::set(self::SETTING, (string) json_encode($entries));
    }

    /**
     * @param  array<array-key, mixed>  $kinds
     * @return list<string>
     */
    private static function normalised(array $kinds): array
    {
        return array_values(array_intersect(self::KINDS, $kinds));
    }
}
