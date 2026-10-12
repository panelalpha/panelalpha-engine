<?php

namespace App\Http\Controllers;

use App\Models\McpActivityLog;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class McpActivityLogController extends Controller
{
    #[OA\Get(
        path: '/mcp-activity-logs',
        summary: 'Page through recorded MCP tool calls',
        description: 'Newest first. `token_name` is the name the caller reported; `project` keeps only the calls '
            . 'that named that project. Panel-facing; not an MCP tool.',
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['MCP'],
        parameters: [
            new OA\Parameter(name: 'token_name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
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
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'token_name' => 'nullable|string|max:255',
            'project'    => 'nullable|string|max:255',
            'page'       => 'nullable|integer|min:1',
        ]);

        $query = McpActivityLog::orderByDesc('created_at');

        if (!empty($params['token_name'])) {
            $query->where('token_name', $params['token_name']);
        }

        if (!empty($params['project'])) {
            $query->where('project', $params['project']);
        }

        return response()->json($query->paginate(20));
    }

    #[OA\Post(
        path: '/mcp-activity-logs',
        summary: 'Record a panel-local tool call in the engine activity log',
        description: '`token_name` names a panel-side token the engine cannot check, so the row also records '
            . 'which engine token wrote it. Panel-facing; not an MCP tool.',
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
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token_name'    => 'required|string|max:255',
            'project'       => 'nullable|string|max:255',
            'tool_name'     => 'required|string|max:255',
            'input'         => 'nullable|array',
            'status'        => 'required|in:success,error',
            'error_message' => 'nullable|string',
        ]);

        // `token_name` names a panel-side token the engine cannot check, so the
        // row records which engine token actually wrote it.
        $user = $request->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $data['token_id'] = $token instanceof PersonalAccessToken ? $token->id : null;

        $log = McpActivityLog::create($data);

        return response()->json(['data' => $log], 201);
    }
}
