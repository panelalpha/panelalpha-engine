<?php

namespace App\Http\Controllers;

use App\Auth\TokenAbilities;
use App\Http\Requests\McpTokenProjectsRequest;
use App\Http\Requests\McpTokenStoreRequest;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class McpTokenController extends Controller
{
    #[OA\Get(
        path: '/mcp-tokens',
        summary: 'List the root admin MCP tokens and their project scope',
        description: 'Pass `project` to keep only the tokens scoped to that project. A token created without '
            . '`projects` is unscoped and reaches every project, so it never matches the filter. These tokens are '
            . 'panel-facing and are not exposed as MCP tools.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(name: 'project', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'MCP tokens', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate(['project' => ['nullable', 'string', 'max:255']]);

        $root = Admin::rootAccount();

        $tokens = $root->tokens()
            ->where(function ($q) use ($params) {
                $q->whereJsonContains('abilities', TokenAbilities::MCP);

                if (!empty($params['project'])) {
                    $q->whereJsonContains('abilities', TokenAbilities::PROJECT_PREFIX . $params['project']);
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($t) => $this->formatToken($t));

        return response()->json(['data' => $tokens]);
    }

    #[OA\Post(
        path: '/mcp-tokens',
        summary: 'Mint an MCP token, optionally scoped to projects',
        description: 'An unscoped token reaches every project. When `projects` is present the token is '
            . 'project-scoped and every operation outside those projects is refused with 403. A project the '
            . 'token creates or clones (staging, clone, push) is not added to its scope: the scope is fixed at '
            . 'mint time, so update it with PUT /mcp-tokens/{id}/projects before the token can reach the new '
            . 'project. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Connect AI'),
                new OA\Property(
                    property: 'projects',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'Existing project usernames. Omit for an unscoped token. A project created or cloned after this is not added automatically.',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Token created; the plaintext value is returned once', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(McpTokenStoreRequest $request): JsonResponse
    {
        $params = $request->validated();

        $root     = Admin::rootAccount();
        $newToken = $root->createToken(
            $params['name'],
            TokenAbilities::build(api: false, mcp: true, projects: $params['projects'] ?? null)
        );
        $token    = $newToken->accessToken;

        return response()->json([
            'data' => array_merge(
                $this->formatToken($token),
                ['plain_text_token' => $newToken->plainTextToken]
            )
        ], 201);
    }

    #[OA\Put(
        path: '/mcp-tokens/{id}/projects',
        summary: 'Replace an MCP token project scope',
        description: 'Sets the exact list of projects the token may reach. An empty list scopes it to no project; '
            . 'the change applies to the next call. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['projects'],
            properties: [
                new OA\Property(property: 'projects', type: 'array', items: new OA\Items(type: 'string')),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated token', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Token not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateProjects(int $id, McpTokenProjectsRequest $request): JsonResponse
    {
        $token = $this->findMcpToken($id);
        $token->forceFill([
            'abilities' => TokenAbilities::of($token)->withProjects($request->validated()['projects']),
        ])->save();

        return response()->json(['data' => $this->formatToken($token)]);
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
            ->whereJsonContains('abilities', TokenAbilities::MCP)
            ->firstOrFail();

        assert($token instanceof PersonalAccessToken);
        return $token;
    }

    private function formatToken(PersonalAccessToken $token): array
    {
        return [
            'id'           => $token->id,
            'name'         => $token->name,
            'projects'     => TokenAbilities::of($token)->projects(),
            'last_used_at' => $token->last_used_at,
            'revoked_at'   => $token->revoked_at,
            'created_at'   => $token->created_at,
        ];
    }
}
