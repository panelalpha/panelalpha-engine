<?php

namespace App\Http\Middleware;

use App\Lib\Testing\CoverageRecorder;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class CoverageMiddleware
{
    /**
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        $admin = Auth::user();
        if (! $admin instanceof Admin) {
            return $next($request);
        }

        $token = $admin->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        return CoverageRecorder::around(
            (int) $token->id,
            static fn () => $next($request),
            'request',
        );
    }
}
