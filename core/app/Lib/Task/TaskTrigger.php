<?php

namespace App\Lib\Task;

use App\Mcp\Tools\Api\ApiTool;
use App\Models\Task;

/** Which surface asked for the work a task is about to run. */
final class TaskTrigger
{
    public const MCP = 'mcp';

    public const API = 'api';

    public const CLI = 'cli';

    public const HOOK = 'hook';

    /** An MCP tool call, an API token, or `pae` on the console; null when none of them can be told. */
    public static function current(): ?string
    {
        if (request()?->attributes->get(ApiTool::VIA_ATTRIBUTE) === true) {
            return self::MCP;
        }
        if (Task::currentTokenId() !== null) {
            return self::API;
        }

        return app()->runningInConsole() ? self::CLI : null;
    }
}
