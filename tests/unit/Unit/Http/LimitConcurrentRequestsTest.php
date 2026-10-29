<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\LimitConcurrentRequests;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * A route under LimitConcurrentRequests runs at most its limit of requests at
 * once; one more is answered 429 without running, and every finished request,
 * failed ones too, gives its slot back.
 */
class LimitConcurrentRequestsTest extends TestCase
{
    private const NAME = 'limit-test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_one_request_more_than_the_limit_is_refused_without_running(): void
    {
        $this->holdSlots(2);
        $ran = false;

        try {
            $this->handle(static function () use (&$ran): Response {
                $ran = true;

                return new Response('ran');
            });
            $this->fail('A request over the limit must be refused.');
        } catch (ThrottleRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertFalse($ran);
    }

    public function test_a_request_within_the_limit_runs_and_gives_its_slot_back(): void
    {
        $this->holdSlots(1);

        $this->assertSame('first', $this->handle(static fn (): Response => new Response('first'))->getContent());
        $this->assertSame('second', $this->handle(static fn (): Response => new Response('second'))->getContent());
    }

    public function test_a_failed_request_gives_its_slot_back(): void
    {
        $this->holdSlots(1);

        try {
            $this->handle(static fn (): never => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
        }

        $this->assertSame('after', $this->handle(static fn (): Response => new Response('after'))->getContent());
    }

    public function test_the_wordpress_connection_test_runs_two_at_a_time(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($route): bool => $route->uri() === 'api/wordpress/migrations/test');

        $this->assertNotNull($route);
        $this->assertContains(
            LimitConcurrentRequests::using('wordpress-migration-test', 2, 2 * SourceProbe::DEADLINE_SECONDS),
            $route->gatherMiddleware(),
        );
    }

    private function handle(\Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        return (new LimitConcurrentRequests)->handle(Request::create('/x', 'POST'), $next, self::NAME, '2', '60');
    }

    private function holdSlots(int $count): void
    {
        for ($slot = 1; $slot <= $count; $slot++) {
            $this->assertTrue(Cache::lock('concurrent-requests:'.self::NAME.$slot, 60, 'other-worker')->get());
        }
    }
}
