<?php

/*
|--------------------------------------------------------------------------
| MCP tool exposure
|--------------------------------------------------------------------------
|
| Controls which of the engine's MCP tools a client can see and call. The
| engine exposes one tool per documented API operation, and the API is
| root-equivalent by design, so this is the difference between handing an
| assistant a read-only view of the server and handing it the ability to
| delete a hosting account.
|
| Filters are applied in this order, and each one only ever removes:
|
|   1. toolsets        which groups are on at all
|   2. tools           individual tools added back on top of those groups
|   3. permission_mode a ceiling on what any tool is allowed to do
|   4. denied / denied_regex  removed no matter what enabled them
|
| A tool has to survive every step. Denials always win, and permission_mode
| is a ceiling rather than a default, so naming a destructive tool in
| MCP_TOOLS does not smuggle it past MCP_PERMISSION_MODE=readonly.
|
*/

return [

    /*
    | Toolsets to enable, comma-separated, matched case-insensitively against
    | the tool's group: the OpenAPI tag its endpoint is documented under
    | (projects, domains, files, csf, modsecurity, system, ...) or "engine" for
    | the two hand-written summary tools. "all" enables every group.
    |
    | The default is every group: an install exposes the whole surface, and
    | MCP_TOOLSETS is how an operator narrows it to the groups they want --
    | "projects,deploy,domains,files" is a perfectly good install, and a
    | narrower list is the only way to keep a group's tools off the wire at all.
    |
    | Nothing is hidden for the assistant's own good here. What an enabled tool
    | may do is MCP_PERMISSION_MODE's business, and it is the ceiling that
    | holds -- a destructive tool is only reachable when the operator has said
    | "full". Denying individual tools is MCP_DENIED_TOOLS.
    |
    | `php artisan mcp:tool:list` prints the toolset each tool belongs to, and
    | the current exposed/withheld/total split.
    |
    | An empty value means every group, not none: a setting nobody has touched
    | and a setting that permits everything are the same value here. "No group
    | at all" therefore needs a spelling of its own, and it is any token that
    | is not a group name -- `pae configure mcp` writes `none`, which is what
    | that line means when you find it in the file. Individual tools named in
    | MCP_TOOLS still apply on top of it.
    |
    | MCP_TOOLSETS=engine,servermetrics,domainlogfiles
    */
    'toolsets' => env('MCP_TOOLSETS', 'all'),

    /*
    | Individual tools to enable on top of the toolsets above, comma-separated
    | and matched exactly against the tool name. Use this to expose one
    | operation out of a group you do not otherwise want on.
    |
    | MCP_TOOLS=project_list,project_get
    */
    'tools' => env('MCP_TOOLS'),

    /*
    | The ceiling on what an enabled tool may do:
    |
    |   readonly  only tools that read. Nothing can change server state, and
    |             the reads that hand out a live credential (the CSF UI
    |             password, the mail smarthost settings, app SSO) are held
    |             back too -- ToolPolicy::CREDENTIAL_TOOLS.
    |   modify    reads, plus create and update. No deletes.
    |   full      everything, including deletes.
    |
    | Derived from the HTTP verb behind each tool, with one exception: an
    | operation that answers from a request body without changing anything --
    | source_inspect clones into a temp directory, reports, and deletes it --
    | is declared read-only at generation time and counts as a read here, so
    | "readonly" does not lose it for the verb it had to use. The list of those
    | is explicit and per operation, in GenerateApiToolsCommand.
    |
    | The same verbs drive the readOnly/destructive annotations clients use to
    | decide what to confirm. Those annotations are advisory to the client;
    | this is enforced on the server.
    */
    'permission_mode' => env('MCP_PERMISSION_MODE', 'full'),

    /*
    | Tools to remove, comma-separated, matched against the tool name. A "*"
    | wildcard is supported, so a whole family can go in one entry.
    |
    | Tool names are <resource>_<action>, so "project_*" denies a whole resource
    | and "*_delete" denies a whole class of operation.
    |
    | MCP_DENIED_TOOLS=project_delete,*_delete,system_*
    */
    'denied' => env('MCP_DENIED_TOOLS'),

    /*
    | Same, as a regular expression, for cases a wildcard cannot express. Given
    | without delimiters; matched case-insensitively against the tool name. An
    | invalid pattern is treated as matchking nothing and logged, so a typo here
    | cannot silently disable the denylist.
    |
    | MCP_DENIED_TOOLS_REGEX=^(project|domain)_(delete|suspend)$
    */
    'denied_regex' => env('MCP_DENIED_TOOLS_REGEX'),

    /*
    | Tool search. The full surface is ~200 tools and ~130 KB of schemas that
    | every client loads into its context up front. With this on, tools/list
    | carries only the direct tools below plus search_tools and execute_tools,
    | and every exposed tool -- direct ones included -- is reachable through
    | those two.
    |
    | It changes what is listed, not what is exposed: the catalogue is built
    | from what survived the filters above, so a tool they remove cannot be
    | found or executed either.
    |
    | MCP_TOOL_SEARCH=false lists every exposed tool directly, as before.
    */
    'tool_search' => env('MCP_TOOL_SEARCH', true),

    /*
    | Tools listed directly while tool search is on, comma-separated, "*"
    | wildcards supported. The default is what deploying, verifying and
    | running a project takes; everything else is one search away.
    | "*" lists everything and is the same as MCP_TOOL_SEARCH=false.
    */
    'direct' => env('MCP_DIRECT_TOOLS', implode(',', [
        // Overview
        'metrics_latest',
        'project_list_summary',
        'project_list',
        // Deploy
        'project_create',
        'project_deploy_archive',
        'file_upload',
        'file_write',
        'project_rebuild',
        'deploy_log_get',
        'deploy_cancel',
        'task_get',
        // Verify
        'project_get',
        'project_inspect',
        'app_health_check',
        'container_service_logs',
        // Manage
        'project_suspend',
        'project_unsuspend',
        'project_delete',
        'container_project_action',
        'domain_list',
        'domain_create',
        'ssl_cert_request',
    ])),

];
