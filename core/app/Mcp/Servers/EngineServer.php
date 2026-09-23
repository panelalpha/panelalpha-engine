<?php

namespace App\Mcp\Servers;

use App\Auth\TokenAbilities;
use App\Mcp\ToolPolicy;
use App\Mcp\Tools\MetricsLatestTool;
use App\Mcp\Tools\ProjectListSummaryTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('PanelAlpha Engine')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Management access to this PanelAlpha engine: the hosting projects it serves,
    their domains, databases, files and containers, and the server itself.

    A **project** is one hosting account — its own container, domains, MySQL
    databases, FTP/SFTP accounts, cron jobs and files. Earlier versions of this
    API called a project a "user", and the REST API still answers on the old
    `/users` paths, but every tool here is named for the project.

    Two other things are also called "users" and are **not** projects:
    `mysql_user_*` are MySQL accounts inside a project's database server, and
    `app_user_*` are accounts inside the application deployed into a project
    (WordPress and similar).

    Tool names read `<resource>_<action>` — `project_suspend`, `domain_create`,
    `mysql_database_list`. Actions are `list`, `get`, `create`, `update` and
    `delete`, plus the operation's own verb where there is one (`clone`,
    `rebuild`, `rename`, `install`). Names never contain their parameters, so
    identify a project by passing `name`, not by picking a different tool.

    **A project is `name` on every tool** — `project_get`, `domain_create`,
    `file_write`, `ssh_run` all take `name: "shop"`. The REST API still calls
    that value `username`, so it appears as `username` in what the tools
    return; pass it back as `name`. A project record's own `name` field is an
    unused display label, usually null — not the project name. A MySQL
    database is `dbname` and a MySQL user `dbuser` wherever they appear.

    **Most of these tools change server state, and some are destructive.**
    Deleting a project removes its container, files and databases; suspending
    one takes live sites offline. Read the annotations: read-only tools are
    marked as such, and everything else is marked destructive. Confirm with the
    operator before calling a destructive tool on a production server.

    Git on a project is `git_status` first. The payload's `managed_by` is
    `deploy` (the repo came in at provision; mutating Git is `project_rebuild`)
    or `site_git` (these Git tools). `git_push` commits a dirty tree itself —
    confirm with the operator first.

    Which tools exist here is controlled by the operator, so this list may be
    narrower than the full API — see MCP_TOOLSETS and MCP_PERMISSION_MODE.
    MARKDOWN)]
class EngineServer extends Server
{
    /**
     * Protocol versions this server will speak.
     *
     * Left at the package default, which is every version it implements:
     * 2025-11-25, 2025-06-18, 2025-03-26 and 2024-11-05. The last of those is
     * what the previous hand-rolled server advertised, so clients configured
     * against it keep working untouched; the server now negotiates upwards
     * instead of answering 2024-11-05 to everyone.
     *
     * Note for a later bump: laravel/mcp 1.x drops the `initialize` handshake
     * entirely (MCP 2026-07-28 replaces it with discovery via request _meta)
     * and ships no Initialize handler, so moving to 1.x is a breaking change
     * for every existing client, not a drop-in upgrade.
     */

    /**
     * The tools registered with this MCP server.
     *
     * The two hand-written summaries, plus one generated tool per documented
     * engine API operation. Their names come from app/Mcp/tool-names.php. The generated list is built by
     * `php artisan mcp:tool:generate` from the OpenAPI document, so the
     * tool surface follows the API's own #[OA\...] attributes rather than
     * drifting from them.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        MetricsLatestTool::class,
        ProjectListSummaryTool::class,
    ];

    // One page over the whole catalogue: Cursor and Codex ignore nextCursor
    // and lose every tool past the first page (#55). Both properties matter —
    // perPage() is min($requested ?? $default, $max).
    public int $maxPaginationLength = 1000;

    public int $defaultPaginationLength = 1000;

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $generated = __DIR__ . '/../Tools/Api/generated-tools.php';

        if (is_file($generated)) {
            $this->tools = array_merge($this->tools, require $generated);
        }

        // Applied before registration, not at listing time: a tool that config
        // switches off is absent from the server, so calling it by name fails
        // rather than merely being hidden from tools/list.
        $this->tools = (new ToolPolicy())->filter($this->tools);

        // Then what this token may reach. The package resolves this class
        // inside the route's own middleware pipeline, so the caller is already
        // authenticated here — which is what makes a per-token tools/list
        // possible rather than one list for everyone and a refusal later.
        $this->tools = TokenAbilities::forCurrentRequest()->filterTools($this->tools);
    }
}
