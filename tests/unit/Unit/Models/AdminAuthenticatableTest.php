<?php

namespace Tests\Unit\Models;

use App\Models\Admin;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\TestCase;

class AdminAuthenticatableTest extends TestCase
{
    public function test_admin_is_authenticatable(): void
    {
        $admin = new Admin();
        $admin->id = 7;

        $this->assertInstanceOf(Authenticatable::class, $admin);
        $this->assertSame(7, $admin->getAuthIdentifier());
    }

    /** The 500 behind the phpMyAdmin SSO exchange: throttle keyed a bearer request on the admin. */
    public function test_throttle_can_key_a_request_authenticated_as_admin(): void
    {
        $admin = new Admin();
        $admin->id = 7;
        $request = Request::create('/api/mysql/phpmyadmin-sso-token', 'PUT');
        $request->setUserResolver(fn () => $admin);

        $throttle = new ThrottleRequests(new RateLimiter(new Repository(new ArrayStore())));
        $signature = (new \ReflectionMethod($throttle, 'resolveRequestSignature'))->invoke($throttle, $request);

        $this->assertSame(sha1('7'), $signature);
    }
}
