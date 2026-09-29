<?php

// Only what differs from laravel/mcp's own config/mcp.php; the package merges
// its defaults under this for every other key.

return [

    /*
    | Limits on search_tools / execute_tools (see mcp-tools.php, tool_search).
    |
    | execute_tools puts each tool's result, JSON-escaped, inside its own, and
    | past max_output_bytes it drops them all for an OutputLimitExceeded. The
    | package default of 64 KB would make that the fate of a result a direct
    | call returns fine. Tools bound their own results instead (ApiTool's
    | MAX_RESULT_BYTES, 256 KB), so this only has to leave room for escaping
    | and for a few calls in one batch.
    */
    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => (int) env('MCP_TOOL_SEARCH_MAX_OUTPUT_BYTES', 1024 * 1024),
    ],

];
