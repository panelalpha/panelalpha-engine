<?php

namespace App\Http\Controllers;

use App\Http\Requests\System\McpScopeUpdateRequest;
use App\Mcp\McpScopeSettings;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * What this engine offers AI assistants at all, the global half of `pae configure mcp`. Panel-facing and not an MCP
 * tool: an assistant must not widen what assistants are offered. A token scoped to some projects is refused, as on
 * every global route.
 */
class SystemMcpScopeController extends Controller
{
    public function __construct(private readonly McpScopeSettings $settings) {}

    #[OA\Get(
        path: '/system/mcp-scope',
        summary: 'Read which MCP commands this engine offers',
        description: 'The ceiling (`permission_mode`: readonly, modify, full), the commands selected (`commands`), '
            .'and those actually offered once the ceiling and MCP_DENIED_TOOLS_REGEX apply (`offered`), with a count '
            .'per toolset. `env` is the same as MCP_* lines. A token\'s own command list can only narrow '
            .'this. Panel-facing; not an MCP tool. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        x: ['mcp-hide' => true],
        responses: [
            new OA\Response(response: 200, description: 'The global MCP scope', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/McpScope')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): JsonResponse
    {
        return $this->answer();
    }

    #[OA\Put(
        path: '/system/mcp-scope',
        summary: 'Change which MCP commands this engine offers',
        description: 'A field left out stays as it is; null puts .env-core, or else the default, back in charge. '
            .'`commands` is the exact selection, by name; it is stored as whole toolsets where that reads shorter, '
            .'with exceptions, as MCP_TOOLSETS, MCP_TOOLS and MCP_DENIED_TOOLS would be. `permission_mode` is a '
            .'ceiling no selected command can rise above. Stored in the engine database, where it wins over the '
            .'MCP_* keys of .env-core (`pae configure mcp-tokens` saves the same), and served from the next request; '
            .'a connected client keeps the list it was given until it reconnects. MCP_DENIED_TOOLS_REGEX stays in '
            .'.env-core. Panel-facing; not an MCP tool. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        x: ['mcp-hide' => true],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'permission_mode', type: 'string', nullable: true, enum: ['readonly', 'modify', 'full'], example: 'modify'),
                new OA\Property(property: 'commands', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['project_list', 'project_get'], description: 'Every command to offer; [] offers none.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The global MCP scope now', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/McpScope')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(McpScopeUpdateRequest $request): JsonResponse
    {
        $this->settings->set($request->changes());

        return $this->answer();
    }

    private function answer(): JsonResponse
    {
        return new JsonResponse(['data' => $this->settings->toArray()]);
    }
}
