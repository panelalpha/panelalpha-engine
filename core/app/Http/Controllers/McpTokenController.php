<?php

namespace App\Http\Controllers;

use App\Auth\TokenAbilities;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpTokenController extends Controller
{
    public function index(): JsonResponse
    {
        $root   = Admin::rootAccount();
        $tokens = $root->tokens()
            ->where(function ($q) {
                $q->whereJsonContains('abilities', 'mcp');
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($t) => $this->formatToken($t));

        return response()->json(['data' => $tokens]);
    }

    public function store(Request $request): JsonResponse
    {
        $params = $request->validate(['name' => 'required|string|max:255']);

        $root     = Admin::rootAccount();
        $newToken = $root->createToken($params['name'], TokenAbilities::build(api: false, mcp: true));
        $token    = $newToken->accessToken;

        return response()->json([
            'data' => array_merge(
                $this->formatToken($token),
                ['plain_text_token' => $newToken->plainTextToken]
            )
        ], 201);
    }

    public function revoke(int $id): JsonResponse
    {
        $token = $this->findMcpToken($id);
        $token->forceFill(['revoked_at' => now()])->save();

        return response()->json(['data' => $this->formatToken($token)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $token = $this->findMcpToken($id);
        $token->delete();

        return response()->json(['data' => ['id' => $id]]);
    }

    /**
     * Validate the calling MCP bearer token.
     * Used by the panel's McpAuthMiddleware to verify engine tokens.
     * Route is protected by mcp.auth middleware.
     */
    public function check(): JsonResponse
    {
        return response()->json(['valid' => true]);
    }

    private function findMcpToken(int $id): PersonalAccessToken
    {
        $root  = Admin::rootAccount();
        $token = $root->tokens()
            ->where('id', $id)
            ->whereJsonContains('abilities', 'mcp')
            ->firstOrFail();

        assert($token instanceof PersonalAccessToken);
        return $token;
    }

    private function formatToken(PersonalAccessToken $token): array
    {
        return [
            'id'           => $token->id,
            'name'         => $token->name,
            'last_used_at' => $token->last_used_at,
            'revoked_at'   => $token->revoked_at,
            'created_at'   => $token->created_at,
        ];
    }
}
