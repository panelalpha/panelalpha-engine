<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * At most $limit requests run a route at once, across every PHP-FPM worker;
 * one more is answered 429 straight away. For a route that keeps its worker
 * waiting on something outside the engine, so it cannot take the whole pool
 * the REST API and MCP share. A slot a dead worker held frees itself after
 * $holdSeconds.
 */
class LimitConcurrentRequests
{
    /** The route middleware string for this limit. */
    public static function using(string $name, int $limit, int $holdSeconds): string
    {
        return self::class.':'.$name.','.$limit.','.$holdSeconds;
    }

    public function handle(Request $request, Closure $next, string $name, string $limit, string $holdSeconds): Response
    {
        return Cache::funnel('concurrent-requests:'.$name)
            ->limit((int) $limit)
            ->releaseAfter((int) $holdSeconds)
            ->block(0)
            ->then(
                static fn (): Response => $next($request),
                static fn (): never => throw new ThrottleRequestsException(
                    "{$limit} of these requests are already running. Try again in a moment."
                ),
            );
    }
}
