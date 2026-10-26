<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // This is an API-only application: there is no 'login' route to send a
        // browser to. Naming one anyway made every unauthenticated request that
        // did not ask for JSON die with a RouteNotFoundException (a 500 and a
        // stack trace) instead of a clean 401 -- which is what /mcp returned to
        // anything that omitted an Accept header, since it sits outside the
        // 'api' group and so never had Accept forced by JsonMiddleware.
        return null;
    }
}
