<?php

namespace App\Http\Middleware;

use App\Auth\TokenAbilities;
use App\Http\Responses\Refusal;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpAuthMiddleware
{
    public const NOT_FOR_MCP = 'token_not_for_mcp';

    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        // 401, not the 403 this describes: the MCP spec pairs 401 with the
        // WWW-Authenticate header this stack already adds, and clients expect it.
        if (! TokenAbilities::of($token)->mayUseMcp()) {
            return Refusal::json(401, 'token', self::NOT_FOR_MCP, 'This token was not issued for an assistant; it takes a token minted for one.', null);
        }

        // Already enforced for every guard in AppServiceProvider; kept on the
        // one endpoint that hands an assistant the whole engine.
        if ($token->isRevoked()) {
            return new JsonResponse(['message' => 'Token has been revoked'], 401);
        }

        return $next($request);
    }
}
