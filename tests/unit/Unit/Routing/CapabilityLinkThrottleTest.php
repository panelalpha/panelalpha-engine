<?php

namespace Tests\Unit\Routing;

use App\Providers\RouteServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The unauthenticated routes whose link or token is the capability each keep
 * their own budget per address: an unnamed throttle keys a guest on the domain
 * and address alone, so one route's callers would spend the others' attempts.
 */
class CapabilityLinkThrottleTest extends TestCase
{
    private const ADDRESS = '198.51.100.31';

    private const ATTEMPTS_PER_MINUTE = 60;

    private const DECAY_SECONDS = 60;

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            'plugin package download' => ['GET', '/api/wordpress/migrations/plugin-package/download', 'plugin-package-download'],
            'app SSO token' => ['GET', '/api/projects/someone/app/sso-token', 'app-sso-token'],
            'app SSO token, legacy alias' => ['GET', '/api/users/someone/app/sso-token', 'app-sso-token'],
            'phpMyAdmin SSO token' => ['PUT', '/api/mysql/phpmyadmin-sso-token', 'phpmyadmin-sso-token'],
        ];
    }

    #[DataProvider('routes')]
    public function test_each_route_uses_its_own_named_limiter(string $method, string $uri, string $limiter): void
    {
        $throttles = $this->throttlesOf($method, $uri);

        $this->assertContains(ThrottleRequests::class . ':' . $limiter, $throttles);
        $this->assertNotContains(ThrottleRequests::class . ':60,1', $throttles);
    }

    public function test_each_limiter_allows_sixty_attempts_a_minute_per_address(): void
    {
        foreach (RouteServiceProvider::CAPABILITY_LINK_LIMITERS as $name) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, "no `{$name}` rate limiter is defined");

            $limit = $limiter($this->guestRequest());

            $this->assertInstanceOf(Limit::class, $limit);
            $this->assertSame(self::ATTEMPTS_PER_MINUTE, $limit->maxAttempts, $name);
            $this->assertSame(self::DECAY_SECONDS, $limit->decaySeconds, $name);
            $this->assertSame('ip:' . self::ADDRESS, $limit->key, $name);
        }
    }

    public function test_spending_one_routes_budget_leaves_the_others_untouched(): void
    {
        $spent = 'app-sso-token';

        for ($attempt = 0; $attempt < self::ATTEMPTS_PER_MINUTE; $attempt++) {
            $this->assertSame(Response::HTTP_OK, $this->throttledStatus($spent));
        }
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->throttledStatus($spent));

        foreach (array_diff(RouteServiceProvider::CAPABILITY_LINK_LIMITERS, [$spent]) as $other) {
            $this->assertSame(Response::HTTP_OK, $this->throttledStatus($other), $other);
        }
    }

    private function throttledStatus(string $limiter): int
    {
        try {
            return $this->app->make(ThrottleRequests::class)
                ->handle($this->guestRequest(), fn () => new Response('ok'), $limiter)
                ->getStatusCode();
        } catch (ThrottleRequestsException) {
            return Response::HTTP_TOO_MANY_REQUESTS;
        }
    }

    private function guestRequest(): Request
    {
        return Request::create('/api/anything', 'GET', [], [], [], ['REMOTE_ADDR' => self::ADDRESS]);
    }

    /** @return list<string> */
    private function throttlesOf(string $method, string $uri): array
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        return array_values(array_filter(
            array_map('strval', $this->app->make('router')->gatherRouteMiddleware($route)),
            static fn (string $middleware): bool => str_starts_with($middleware, ThrottleRequests::class . ':')
        ));
    }
}
