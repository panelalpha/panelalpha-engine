<?php

namespace App\Http\Middleware;

use App\Auth\ApiSurface;
use App\Auth\TokenAbilities;
use App\Mcp\Tools\Api\ApiTool;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a token may do on the REST API. `auth:api` calls `tokenCan` nowhere, so
 * before this a token issued for anything reached every route on the engine.
 *
 * A call is authorised by the door it arrived at, so this asks nothing about
 * MCP. {@see ApiTool} dispatches tool calls through this router in-process
 * and marks them on the `attributes` bag — server-side, never populated from
 * an inbound request, so only this engine can claim it.
 */
class EnsureTokenMayUseApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Already authorised at the other door.
        if ($request->attributes->get(ApiTool::VIA_ATTRIBUTE) === true) {
            return $next($request);
        }

        $user = $request->user();

        // A caller with no token API has no token to limit: the CLI dispatches
        // routes in-process as a plain authenticatable, not a token-bearing Admin.
        $token = $user !== null && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        // Sanctum has decided who this is; not ours to second-guess.
        if (!$token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $abilities = TokenAbilities::of($token);

        if (!$abilities->mayUseApi()) {
            return $this->refuse(
                'This token was not issued for the API. Mint one with `pae api:token:create`.'
            );
        }

        $operation = ApiSurface::keyFor($request->route(), $request->method());

        if (!$abilities->mayCallRoute($operation)) {
            return $this->refuse(sprintf(
                'This token is limited to part of the API, and %s is not in it.',
                $operation ?? 'this route'
            ));
        }

        return $next($request);
    }

    private function refuse(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 403);
    }
}
