<?php

namespace App\Http\Controllers;

use App\Auth\CallingToken;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * What any token may do to itself: replace itself, or end itself. Neither
 * route takes an id, so no token can reach another. Hidden from MCP: a tool
 * call carries the assistant's own token, and rotating or revoking that from
 * inside a conversation only locks the assistant out.
 */
class TokenController extends Controller
{
    #[OA\Post(
        path: '/tokens/current/rotate',
        summary: 'Replace the calling token with a new one of the same name and abilities',
        description: 'The new token is issued for as long as the calling token was: a token created to last '
            .'one day is replaced by one that lasts one day from now, and a token without an expiry by one '
            .'without. A PanelAlpha Connect token is replaced by one that expires in 30 days. The calling token '
            .'keeps working for 10 minutes, and rotating it again within those 10 minutes returns the same new token.',
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        x: ['mcp-hide' => true],
        responses: [
            new OA\Response(response: 200, description: 'The new token', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'token_id', type: 'integer'),
                    new OA\Property(property: 'abilities', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                ])],
            )),
            new OA\Response(response: 400, description: 'The request carries no token'),
        ],
    )]
    public function rotate(Request $request): JsonResponse
    {
        $token = $this->calling($request);
        if ($token === null) {
            return new JsonResponse(['message' => 'Only a token can rotate itself.'], 400);
        }

        return new JsonResponse(['data' => CallingToken::rotate($token)]);
    }

    #[OA\Delete(
        path: '/tokens/current',
        summary: 'Revoke the calling token',
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        x: ['mcp-hide' => true],
        responses: [
            new OA\Response(response: 204, description: 'Revoked'),
            new OA\Response(response: 400, description: 'The request carries no token'),
        ],
    )]
    public function destroy(Request $request): Response|JsonResponse
    {
        $token = $this->calling($request);
        if ($token === null) {
            return new JsonResponse(['message' => 'Only a token can revoke itself.'], 400);
        }

        CallingToken::revoke($token);

        return new Response(null, 204);
    }

    private function calling(Request $request): ?PersonalAccessToken
    {
        $user = $request->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
