<?php

namespace App\Http\Controllers;

use App\Lib\Project\ApiAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The projects allowed to use the Engine API. Panel-facing: it mints,
 * re-scopes and revokes tokens for accounts and opens the host's firewall to
 * them, so a token scoped to some projects is refused here like on every other
 * global route.
 *
 * A project's token itself is never returned -- not the value, not the stored
 * copy -- so an operator who lost it rotates instead of reading it.
 */
class SystemApiAccessController extends Controller
{
    #[OA\Get(
        path: '/system/api-access',
        summary: 'List the projects allowed to use the Engine API',
        description: 'One entry per allowed project: the scope its token was minted with, whether the token may '
            . 'call MCP tools too, when it was allowed and its token id. The tokens themselves are never returned. '
            . 'Only container projects receive the API address, the token and the certificate in their environment; '
            . 'a classic project is allowed and reachable, and gets nothing in its container. Refused with 403 for a '
            . 'token scoped to some projects: it is not a project route.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Lists the projects allowed to call this engine\'s API, with the scope of each token. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The allowed projects', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ApiAccess')),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => (new ApiAccess())->all()]);
    }

    #[OA\Put(
        path: '/system/api-access/{project}',
        summary: 'Allow a project to use the Engine API, or change its scope',
        description: 'Mints a token for the project with the abilities the scope implies, lets the project\'s '
            . 'containers reach the API port, and queues an apply that delivers the API address, the token and, '
            . 'where a client needs one, the certificate to the running application without a rebuild. Allowing a '
            . 'project that already carries this scope and this `mcp` flag changes nothing at all; a changed scope '
            . 'or flag replaces the token, and the old one is revoked once the new one is in use. `scope` is `self` '
            . '(only this project), `all` (every project on this engine), or a list of existing project '
            . 'usernames; an unknown one is refused with 422. Only a container project may be allowed: a classic '
            . 'project has no container to receive the environment, so it is refused with 422 and the code '
            . 'api_access_container_only. Refused with 403 for a token scoped to some projects. To replace a token '
            . 'without changing the scope, use POST /system/api-access/{project}/rotate.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Allows a project to call this engine\'s API, minting a token limited to the given scope (self, all, or a list of existing projects) and opening the host firewall to the project\'s containers. Allowing a project that already carries that scope changes nothing. A classic (PHP hosting) project is refused. Refused 403 for a project-scoped token.'],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['scope'],
            properties: [
                new OA\Property(
                    property: 'scope',
                    description: 'What the token may act on. `self`, `all`, or a list of existing project usernames.',
                    oneOf: [
                        new OA\Schema(type: 'string', enum: ['self', 'all']),
                        new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
                    ],
                    example: 'all',
                ),
                new OA\Property(property: 'mcp', type: 'boolean', nullable: true, description: 'Whether the token may also call MCP tools.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The entry, without the token', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ApiAccess')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'A scope that is neither self, all, nor a list of existing projects; mcp, not a boolean; or a project that is not a container project', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(string $project, Request $request): JsonResponse
    {
        $user = $this->projectOr404($project);
        /** @var array{scope: mixed, mcp?: bool} $params */
        $params = $request->validate([
            'scope' => ['required'],
            'mcp' => ['sometimes', 'boolean'],
        ]);

        $scope = $params['scope'];
        $entry = (new ApiAccess())->allow(
            $user,
            is_array($scope) ? $scope : (string) $scope,
            (bool) ($params['mcp'] ?? false)
        );

        return new JsonResponse(['data' => $entry]);
    }

    #[OA\Post(
        path: '/system/api-access/{project}/rotate',
        summary: 'Replace an allowed project\'s Engine API token',
        description: 'Mints a new token with the scope the project already carries and queues the apply that hands '
            . 'it to the running application without a rebuild; the token it replaces is revoked only once that '
            . 'apply has succeeded, and until then it stays valid and is reported as `pending_revoke_token_id` with '
            . 'a warning. The apply is asynchronous: the answer names it in `task_id`. A project that is not '
            . 'allowed, or not a container project, is refused with 422. Refused with 403 for a token scoped to '
            . 'some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Replaces an allowed project\'s Engine API token without changing its scope, and answers the entry and the id of the apply task that delivers it. Refused 403 for a project-scoped token.'],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'The new entry, and the apply task that carries it', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/ApiAccess'),
                    new OA\Property(property: 'task_id', type: 'integer', example: 42),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The project is not allowed to use the API, or is not a container project', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function rotate(string $project): JsonResponse
    {
        $user = $this->projectOr404($project);
        $entry = (new ApiAccess())->rotate($user, $taskId);

        return new JsonResponse(['data' => $entry, 'task_id' => $taskId], 202);
    }

    #[OA\Delete(
        path: '/system/api-access/{project}',
        summary: 'Stop a project using the Engine API',
        description: 'Revokes the project\'s token at once, removes its firewall rule and queues an apply that '
            . 'takes the API address, the token and the certificate back out of the running application. A project '
            . 'that was not allowed is answered with `removed: false` and nothing changes. Refused with 403 for a '
            . 'token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Revokes a project\'s Engine API token and closes the host firewall to its containers. Refused 403 for a project-scoped token.'],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Whether an allowance was removed', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'project', type: 'string'),
                        new OA\Property(property: 'removed', type: 'boolean'),
                    ], type: 'object'),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $project): JsonResponse
    {
        $user = $this->projectOr404($project);

        return new JsonResponse(['data' => [
            'project' => $user->username,
            'removed' => (new ApiAccess())->deny($user),
        ]]);
    }
}
