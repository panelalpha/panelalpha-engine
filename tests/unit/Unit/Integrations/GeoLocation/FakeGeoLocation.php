<?php

namespace Tests\Unit\Integrations\GeoLocation;

use App\Integrations\GeoLocation\GeoLocation;

final class FakeGeoLocation implements GeoLocation
{
    /**
     * @param array<string, array{country?: string, country_code?: string, region?: string, region_code?: string}> $records
     */
    public function __construct(private array $records = [])
    {
    }

    public function updateDatabase(bool $force = false): void
    {
    }

    public function databasePath(): ?string
    {
        return null;
    }

    public function lookup(string $ip): ?array
    {
        return $this->records[$ip] ?? null;
    }
}
