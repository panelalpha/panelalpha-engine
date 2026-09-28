<?php

namespace App\Http\Controllers;

use App\Models\McpActivityLog;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'token_name' => 'nullable|string|max:255',
            'page'       => 'nullable|integer|min:1',
        ]);

        $query = McpActivityLog::orderByDesc('created_at');

        if ($request->filled('token_name')) {
            $query->where('token_name', $request->input('token_name'));
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Allow the panel to log panel-local tool calls into the engine activity log.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token_name'    => 'required|string|max:255',
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
