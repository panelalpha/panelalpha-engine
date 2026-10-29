<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureSwaggerEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! env('SWAGGER_ENABLED', false)) {
            return new JsonResponse(['message' => 'Not found'], 404);
        }

        return $next($request);
    }
}
