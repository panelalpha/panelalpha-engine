<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Http\Middleware\McpAuthMiddleware;
use App\Lib\Findings\Hints\Hint;

/** A token minted for an assistant; tokens are minted on the host. */
final class MintMcpToken extends Hint
{
    public function resolves(): array
    {
        return [McpAuthMiddleware::NOT_FOR_MCP];
    }

    public function params(): array
    {
        return ['name' => null];
    }

    public function cli(): string
    {
        return 'mcp:token:create {name}';
    }
}
