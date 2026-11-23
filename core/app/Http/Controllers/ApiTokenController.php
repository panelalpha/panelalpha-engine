<?php

namespace App\Http\Controllers;

use App\Auth\OperatorTokens;
use App\Http\Requests\ApiTokenRoutesRequest;
use App\Http\Requests\ApiTokenStoreRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Tokens for the operator's own software, and which REST operations each may call: what `pae configure api-tokens`
 * does, for a token with a lower scope than the caller's. Whatever is minted or changed here stays within the
 * caller's own token, and the caller sees and manages only tokens that do. Panel-facing and not MCP tools, as the
 * MCP tokens are. A token scoped to some projects is refused, as on every global route.
 */
class ApiTokenController extends Controller
{
    #[OA\Get(
        path: '/api-tokens',
        summary: 'List the API tokens',
        description: 'Every token of the root account that may call the REST API, oldest first: its name, the '
            .'operations it is limited to (`routes`, null for every one), the projects it may reach (`projects`, null '
            .'for every one), expiry and last use. Only tokens within the caller\'s own are listed: no surface, '
            .'operation or project it lacks, and no later expiry. A token from before tokens had kinds (`legacy`) is '
            .'listed here and under /mcp-tokens alike, to an unlimited caller. The plaintext is never answered. '
            .'Panel-facing; not an MCP tool. Refused with 403 for a token scoped to some projects.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        responses: [
            new OA\Response(response: 200, description: 'API tokens', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperatorApiToken'))],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        $tokens = OperatorTokens::api();

        return new JsonResponse(['data' => array_map($tokens->toArray(...), $tokens->all())]);
    }

    #[OA\Post(
        path: '/api-tokens',
        summary: 'Mint an API token',
        description: 'A token for the REST API only, answered once as `plain_text_token` and never again. '
            .'`expires_in` is a number and a unit (30m, 12h, 90d, 2w); left out, it never expires. `routes` limits it '
            .'to those operations, written "VERB /path" as in this document; left out, it may call every one, and an empty '
            .'list is refused. '
            .'`projects` scopes it to those projects. The token stays within the caller\'s own: a caller limited to some '
            .'operations must name `routes` among its own, and one that expires must give a lifetime that ends by then; '
            .'otherwise 422 on that field. Refused with 403 to a token scoped to some projects. Panel-facing; not an MCP '
            .'tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'deploy-bot'),
                new OA\Property(property: 'expires_in', type: 'string', nullable: true, example: '90d'),
                new OA\Property(property: 'routes', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['GET /projects', 'POST /projects/{username}/push']),
                new OA\Property(property: 'projects', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['alice']),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Minted; the plaintext is answered once', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/OperatorApiToken')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or more than the caller\'s own token holds', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(ApiTokenStoreRequest $request): JsonResponse
    {
        $tokens = OperatorTokens::api();
        $minted = $tokens->mint(
            (string) $request->validated('name'),
            $request->validated('expires_in'),
            $request->validated('projects'),
            $request->validated('routes'),
        );

        return new JsonResponse([
            'data' => [...$tokens->toArray($tokens->find((int) $minted->accessToken->getKey())), 'plain_text_token' => $minted->plainTextToken],
        ], 201);
    }

    #[OA\Put(
        path: '/api-tokens/{id}/routes',
        summary: 'Limit which operations an API token may call',
        description: 'Replaces the token\'s operation list: `routes` names every operation it may call, null lifts '
            .'the limit, and an empty list is refused (422): revoke the token to stop it. A token from before tokens had '
            .'kinds keeps only the REST API once limited. Applies to its next call. The result stays within the '
            .'caller\'s own token: a caller limited to some operations may name only those, and may not lift the limit '
            .'(422). A token that reaches further than the caller\'s is refused with 403. Panel-facing; not an MCP '
            .'tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['routes'],
            properties: [new OA\Property(property: 'routes', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['GET /projects'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The token now', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/OperatorApiToken')],
            )),
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s, or the caller is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No API token has that id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or more than the caller\'s own token holds', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateRoutes(int $id, ApiTokenRoutesRequest $request): JsonResponse
    {
        $tokens = OperatorTokens::api();

        return new JsonResponse(['data' => $tokens->toArray($tokens->limit($tokens->find($id), $request->routes()))]);
    }

    #[OA\Put(
        path: '/api-tokens/{id}/revoke',
        summary: 'Revoke an API token',
        description: 'Refuses the token everywhere at once, for good. It stays listed with `revoked_at` set, so the '
            .'audit trail keeps its name. Revoking the token making this call ends the caller\'s own access. '
            .'Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The token, revoked', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/OperatorApiToken')],
            )),
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s, or the caller is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No API token has that id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function revoke(int $id): JsonResponse
    {
        $tokens = OperatorTokens::api();

        return new JsonResponse(['data' => $tokens->toArray($tokens->revoke($tokens->find($id)))]);
    }

    #[OA\Delete(
        path: '/api-tokens/{id}',
        summary: 'Delete an API token',
        description: 'Removes the token, which refuses it everywhere at once and leaves no record of it; revoking keeps '
            .'its name for the audit trail. Whatever uses it stops working. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Tokens'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer')])],
            )),
            new OA\Response(response: 403, description: 'That token reaches further than the caller\'s, or the caller is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No API token has that id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $tokens = OperatorTokens::api();
        $tokens->delete($tokens->find($id));

        return new JsonResponse(['data' => ['id' => $id]]);
    }
}
