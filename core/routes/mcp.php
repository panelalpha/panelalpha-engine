<?php

use App\Http\Controllers\McpTokenController;
use App\Http\Middleware\McpActivityLogMiddleware;
use App\Http\Middleware\McpTransportMiddleware;
use App\Mcp\Servers\EngineServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Streamable HTTP transport. laravel/mcp registers POST for the JSON-RPC
// endpoint and answers GET/DELETE with 405, as the spec requires.
Mcp::web('/mcp', EngineServer::class)
    ->middleware([McpTransportMiddleware::class, McpActivityLogMiddleware::class]);

Route::get('/mcp/check', [McpTokenController::class, 'check']);
