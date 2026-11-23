<?php

namespace App\Http\Controllers;

use App\Auth\CallerScope;
use App\Auth\OperatorTokens;
use App\Auth\TokenAbilities;
use App\Http\Requests\McpTokenCommandsRequest;
use App\Http\Requests\McpTokenProjectsRequest;
use App\Http\Requests\McpTokenStoreRequest;
use App\Mcp\ToolExposure;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Tokens for AI assistants. Whatever is minted or changed here stays within the caller's own token, and the caller
 * sees and manages only tokens that do: {@see CallerScope}.
 */
class McpTokenController extends Controller
{
    #[OA\Get(
        path: '/mcp-tokens',
        summary: 'List the root admin MCP tokens and their project scope',
        description: 'Pass `project` to keep only the tokens scoped to that project. A token created without '
            .'`projects` is unscoped and reaches every project, so it never matches the filter. Only tokens within the '
            .'caller\'s own are listed. These tokens are panel-facing and are not exposed as MCP tools.',
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

        $project = empty($params['project']) ? null : $params['project'];

        $tokens = collect(OperatorTokens::mcp()->all())
            ->filter(fn (PersonalAccessToken $t): bool => $project === null || in_array($project, TokenAbilities::of($t)->projects() ?? [], true))
            ->reverse()
            ->map(fn (PersonalAccessToken $t): array => $this->formatToken($t))
            ->values();

        return response()->json(['data' => $tokens]);
    }

    #[OA\Post(
        path: '/mcp-tokens',
        summary: 'Mint an MCP token, optionally scoped to projects',
        description: 'An unscoped token reaches every project. When `projects` is present the token is '
            .'project-scoped and every operation outside those projects is refused with 403. A project named '
            .'in a request -- the target of a push, the name a clone or staging copy is created under -- must '
            .'be on the list already: the scope is fixed at mint time, so update it with '
            .'PUT /mcp-tokens/{id}/projects to let the token reach a project added since. `expires_in` is a number '
            .'and a unit (30m, 12h, 90d, 2w); left out, the token never expires. `commands` limits it to those MCP '
            .'commands. The token stays within the caller\'s own: the caller must hold MCP itself (403 otherwise), a '
            .'caller limited to some commands must name `commands` among its own, and one that expires must give a '
            .'lifetime that ends by then (422 on that field). Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Connect AI'),
                new OA\Property(property: 'expires_in', type: 'string', nullable: true, example: '90d', description: 'How long the token lasts; omit for one that never expires.'),
                new OA\Property(
                    property: 'projects',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'Existing project usernames. Omit for an unscoped token. A project created or cloned after this is not added automatically.',
                ),
                new OA\Property(property: 'commands', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['project_list', 'project_get'], description: 'The MCP commands it may call, from GET /mcp-tokens/commands; omit or null for every one.'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Token created; the plaintext value is returned once', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token does not hold MCP, or is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or more than the caller\'s own token holds', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(McpTokenStoreRequest $request): JsonResponse
    {
        $params = $request->validated();

        $newToken = OperatorTokens::mcp()->mint($params['name'], $params['expires_in'] ?? null, $params['projects'] ?? null, $request->commands());
        $token = $newToken->accessToken;
        assert($token instanceof PersonalAccessToken);

        return response()->json([
            'data' => array_merge(
                $this->formatToken($token),
                ['plain_text_token' => $newToken->plainTextToken]
            ),
        ], 201);
    }

    #[OA\Put(
        path: '/mcp-tokens/{id}/projects',
        summary: 'Replace an MCP token project scope',
        description: 'Sets the exact list of projects the token may reach. An empty list scopes it to no project; '
            .'the change applies to the next call. A caller scoped to some projects may name only those (422). A token '
            .'that reaches further than the caller\'s is refused with 403. Panel-facing; not an MCP tool.',
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
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Token not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateProjects(int $id, McpTokenProjectsRequest $request): JsonResponse
    {
        $token = OperatorTokens::mcp()->scope($this->findMcpToken($id), array_values($request->validated()['projects']));

        return response()->json(['data' => $this->formatToken($token)]);
    }

    #[OA\Get(
        path: '/mcp-tokens/commands',
        summary: 'List the MCP tools a token can be limited to',
        description: 'Every tool this engine serves now, with what it does to the server: `access` is `read`, '
            .'`write` or `secret` (a read that hands out a credential), `deletes` marks a DELETE operation, and '
            .'`project_scoped` says whether a project-scoped token can reach it at all. `toolset` is the group '
            .'the tool is filed under. Pass names from here as `commands`. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        responses: [
            new OA\Response(response: 200, description: 'MCP tools', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'project_get'),
                        new OA\Property(property: 'toolset', type: 'string', example: 'projects'),
                        new OA\Property(property: 'access', type: 'string', enum: ['read', 'write', 'secret']),
                        new OA\Property(property: 'deletes', type: 'boolean'),
                        new OA\Property(property: 'project_scoped', type: 'boolean'),
                    ],
                    type: 'object',
                ))],
            )),
        ],
    )]
    public function commands(): JsonResponse
    {
        $exposure = ToolExposure::current();
        $policy = $exposure->policy();
        $served = $exposure->exposed();
        $scoped = array_flip(TokenAbilities::fromList([TokenAbilities::MCP, TokenAbilities::PROJECT_SCOPE])->filterTools($served));

        $commands = array_map(fn (string $class): array => [
            'name' => $policy->nameOf($class),
            'toolset' => $policy->toolsetOf($class),
            'access' => $policy->accessOf($class),
            'deletes' => $policy->verbOf($class) === 'DELETE',
            'project_scoped' => isset($scoped[$class]),
        ], $served);

        usort($commands, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return response()->json(['data' => $commands]);
    }

    #[OA\Put(
        path: '/mcp-tokens/{id}/commands',
        summary: 'Limit which MCP commands a token may call',
        description: 'Replaces the token\'s command list: `commands` names every command it may call, null lifts the '
            .'limit so it follows what the engine offers. [] is refused: revoke a token to stop it. The engine\'s global scope '
            .'(GET /system/mcp-scope) still applies on top. Applies to its next call. The result stays within the '
            .'caller\'s own token: a caller limited to some commands may name only those, and may not lift the limit '
            .'(422). A token that reaches further than the caller\'s is refused with 403. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['commands'],
            properties: [new OA\Property(property: 'commands', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['project_list', 'project_get'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated token', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s, or the caller is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Token not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateCommands(int $id, McpTokenCommandsRequest $request): JsonResponse
    {
        $token = OperatorTokens::mcp()->limit($this->findMcpToken($id), $request->commands());

        return response()->json(['data' => $this->formatToken($token)]);
    }

    #[OA\Put(
        path: '/mcp-tokens/{id}/revoke',
        summary: 'Revoke an MCP token',
        description: 'Marks the token revoked, so it is refused everywhere at once and cannot be un-revoked. It stays '
            .'on the list with `revoked_at` set. A token that reaches further than the caller\'s is refused with 403. '
            .'Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Revoked token', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Token not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function revoke(int $id): JsonResponse
    {
        $token = OperatorTokens::mcp()->revoke($this->findMcpToken($id));

        return response()->json(['data' => $this->formatToken($token)]);
    }

    #[OA\Delete(
        path: '/mcp-tokens/{id}',
        summary: 'Delete an MCP token',
        description: 'Removes the token, which refuses it everywhere at once. Activity log rows it wrote keep their '
            .'token id. A token that reaches further than the caller\'s is refused with 403. Panel-facing; not an MCP '
            .'tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The deleted token\'s id', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Token not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        OperatorTokens::mcp()->delete($this->findMcpToken($id));

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
        return OperatorTokens::mcp()->find($id);
    }

    private function formatToken(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'projects' => TokenAbilities::of($token)->projects(),
            'commands' => TokenAbilities::of($token)->commands(),
            'expires_at' => $token->expires_at,
            'last_used_at' => $token->last_used_at,
            'revoked_at' => $token->revoked_at,
            'created_at' => $token->created_at,
        ];
    }
}
