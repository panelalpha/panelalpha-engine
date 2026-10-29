<?php

namespace App\Http\Controllers;

use App\Http\Requests\McpActivityLogIndexRequest;
use App\Http\Requests\McpActivityLogStoreRequest;
use App\Models\McpActivityLog;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class McpActivityLogController extends Controller
{
    #[OA\Get(
        path: '/mcp-activity-logs',
        summary: 'Page through recorded MCP tool calls',
        description: 'Newest first, 20 per page. `token_ids[]` keeps the calls made with any of those tokens, '
            .'including tokens deleted since; `token_name` keeps the calls whose recorded token name contains '
            .'that text, ignoring case; `project` keeps only the calls that named that project. Filters combine '
            .'with AND. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(
                name: 'token_ids[]',
                description: 'Token ids whose calls to list, repeated once per id (at most 1000).',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer', minimum: 1), maxItems: 1000),
                style: 'form',
                explode: true,
            ),
            new OA\Parameter(
                name: 'token_name',
                description: 'Text the recorded token name contains, case-insensitive.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', maxLength: 255),
            ),
            new OA\Parameter(name: 'project', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated activity log', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'meta', type: 'object'),
                    new OA\Property(property: 'links', type: 'object'),
                ],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function index(McpActivityLogIndexRequest $request): JsonResponse
    {
        $query = McpActivityLog::matching($request->tokenIds(), $request->tokenName(), $request->project())
            ->orderByDesc('created_at');

        return response()->json($query->paginate(20)->withQueryString());
    }

    #[OA\Post(
        path: '/mcp-activity-logs',
        summary: 'Record a panel-local tool call in the engine activity log',
        description: '`token_name` names a panel-side token the engine cannot check, so the row also records '
            .'which engine token wrote it. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token_name', 'tool_name', 'status'],
            properties: [
                new OA\Property(property: 'token_name', type: 'string'),
                new OA\Property(property: 'project', type: 'string', nullable: true),
                new OA\Property(property: 'tool_name', type: 'string'),
                new OA\Property(property: 'input', type: 'object', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['success', 'error']),
                new OA\Property(property: 'error_message', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Activity log entry created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(McpActivityLogStoreRequest $request): JsonResponse
    {
        $log = McpActivityLog::create($request->entry());

        return response()->json(['data' => $log], 201);
    }
}
