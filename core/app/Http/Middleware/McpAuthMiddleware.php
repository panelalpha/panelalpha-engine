<?php

namespace App\Http\Middleware;

use App\Auth\TokenAbilities;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class McpAuthMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->user()?->currentAccessToken();

        if (!$token instanceof PersonalAccessToken) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // 401, not the 403 this describes: the MCP spec pairs 401 with the
        // WWW-Authenticate header this stack already adds, and clients expect it.
        if (!TokenAbilities::of($token)->mayUseMcp()) {
            return new JsonResponse([
                'error' => 'This token was not issued for an assistant. Mint one with `pae connect`.',
            ], 401);
        }

        // Already enforced for every guard in AppServiceProvider; kept on the
        // one endpoint that hands an assistant the whole engine.
        if ($token->isRevoked()) {
            return new JsonResponse(['error' => 'Token has been revoked'], 401);
        }

        return $next($request);
    }
}
