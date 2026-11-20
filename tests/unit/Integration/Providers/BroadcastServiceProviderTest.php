<?php

namespace Tests\Integration\Providers;

use App\Providers\BroadcastServiceProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The broadcast provider, when an installation enables it, serves the channel auth route. */
class BroadcastServiceProviderTest extends TestCase
{
    public function test_booting_it_registers_the_broadcast_auth_route(): void
    {
        $this->assertFalse($this->authRouteRegistered());

        (new BroadcastServiceProvider($this->app))->boot();

        $this->assertTrue($this->authRouteRegistered());
    }

    private function authRouteRegistered(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'broadcasting/auth') {
                return true;
            }
        }

        return false;
    }
}
