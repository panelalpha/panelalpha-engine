<?php

namespace App\Auth;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;

/**
 * The personal access token a request carries, if any.
 *
 * Sanctum decides who the caller is; this answers only whether that caller
 * arrived with a token, which is what separates an API or MCP call from the
 * console dispatching a route in-process as a plain authenticatable.
 */
class RequestToken
{
    public static function of(Request $request): ?PersonalAccessToken
    {
        $user = $request->user();

        $token = $user !== null && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
