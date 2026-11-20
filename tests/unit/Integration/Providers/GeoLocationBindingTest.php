<?php

namespace Tests\Integration\Providers;

use App\Integrations\GeoLocation\GeoLocation;
use InvalidArgumentException;
use Tests\TestCase;

/** A geolocation driver the engine does not ship is refused by name when first needed. */
class GeoLocationBindingTest extends TestCase
{
    public function test_an_unknown_driver_is_named_in_the_refusal(): void
    {
        config(['geolocation.driver' => 'maxmind']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown geolocation driver [maxmind].');

        $this->app->make(GeoLocation::class);
    }
}
