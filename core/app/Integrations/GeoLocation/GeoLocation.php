<?php

namespace App\Integrations\GeoLocation;

/**
 * @psalm-type GeoRecord = array{
 *   country?: string,
 *   country_code?: string,
 *   region?: string,
 *   region_code?: string
 * }
 */
interface GeoLocation
{
    public function updateDatabase(bool $force = false): void;

    public function databasePath(): ?string;

    /**
     * @psalm-return GeoRecord|null
     */
    public function lookup(string $ip): ?array;
}
