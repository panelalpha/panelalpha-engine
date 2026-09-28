<?php

namespace Tests\Unit\Routing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * engine#48 item 23: `throttle:api` was commented out of the api group, so
 * /api answered a token, or a tokenless route, as fast as anyone could ask.
 */
class ApiRateLimitTest extends TestCase
{
    public function test_the_api_group_is_throttled(): void
    {
        $group = $this->app->make(HttpKernel::class)->getMiddlewareGroups()['api'];

        $this->assertContains('throttle:api', $group);
    }

    public function test_an_authenticated_caller_is_counted_per_token(): void
    {
        $limit = $this->limitFor($this->requestWithToken(7));

        $this->assertSame(3000, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('token:7', $limit->key);
    }

    public function test_anyone_else_is_counted_per_address(): void
    {
        $limit = $this->limitFor(Request::create('/api/projects', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']));

        $this->assertSame(120, $limit->maxAttempts);
        $this->assertSame('ip:203.0.113.7', $limit->key);
    }

    public function test_zero_turns_a_limit_off(): void
    {
        config(['auth.api_rate_limit.per_token' => 0, 'auth.api_rate_limit.per_ip' => 0]);

        $this->assertInstanceOf(Unlimited::class, $this->limitFor($this->requestWithToken(7)));
        $this->assertInstanceOf(Unlimited::class, $this->limitFor(Request::create('/api/projects')));
    }

    public function test_a_flood_on_a_tokenless_route_gets_429(): void
    {
        // The phpMyAdmin exchange takes no token; PmaSso answers 404 to this
        // address, and the group throttle counts the attempts before it does.
        config(['auth.api_rate_limit.per_ip' => 2]);

        $this->assertSame(404, $this->exchangeFrom('198.51.100.23'));
        $this->assertSame(404, $this->exchangeFrom('198.51.100.23'));
        $this->assertSame(429, $this->exchangeFrom('198.51.100.23'));

        // Another address has its own bucket.
        $this->assertSame(404, $this->exchangeFrom('198.51.100.24'));
    }

    private function exchangeFrom(string $address): int
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->putJson('/api/mysql/phpmyadmin-sso-token', ['token' => 'x'])
            ->status();
    }

    public function test_the_site_password_subrequests_are_not_throttled(): void
    {
        // sites-http asks these for every request to a protected site, all from
        // one gateway address; one bucket for all of them would lock sites out.
        foreach (['check', 'gate'] as $action) {
            $this->assertNotContains(
                ThrottleRequests::class . ':api',
                $this->middlewareOf('GET', "/api/internal/site-password/alice/{$action}"),
                "site-password {$action} is throttled"
            );
        }

        // The password form is a visitor's own request, forwarded with its
        // address, and a brute-force target: it stays counted.
        $this->assertContains(
            ThrottleRequests::class . ':api',
            $this->middlewareOf('POST', '/api/internal/site-password/alice/login')
        );
        $this->assertContains(ThrottleRequests::class . ':api', $this->middlewareOf('GET', '/api/projects'));
    }

    private function limitFor(Request $request): Limit
    {
        $limiter = RateLimiter::limiter('api');
        $this->assertNotNull($limiter, 'no `api` rate limiter is defined');

        $limit = $limiter($request);
        $this->assertInstanceOf(Limit::class, $limit);

        return $limit;
    }

    private function requestWithToken(int $id): Request
    {
        $request = Request::create('/api/projects', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);
        $user = new class ($id) {
            public function __construct(private int $id)
            {
            }

            public function currentAccessToken(): object
            {
                return (object) ['id' => $this->id];
            }
        };
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    /**
     * @return list<string>
     */
    private function middlewareOf(string $method, string $uri): array
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        return array_values(array_map('strval', $this->app->make('router')->gatherRouteMiddleware($route)));
    }
}
