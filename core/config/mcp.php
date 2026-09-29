<?php

// Only what differs from laravel/mcp's own config/mcp.php; the package merges
// its defaults under this for every other key.

return [

    /*
    | Limits on search_tools / execute_tools (see mcp-tools.php, tool_search).
    |
    | The package caps output at 64 KB. A tool called directly has no cap, and
    | deploy_log_get, file_download and task_log_list routinely exceed that,
    | so the same tool would fail only because it was reached through the
    | catalogue.
    */
    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => (int) env('MCP_TOOL_SEARCH_MAX_OUTPUT_BYTES', 16 * 1024 * 1024),
    ],

];
