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
    Manages this PanelAlpha engine: its hosting projects and the server.

    A **project** is one hosting account: its own container, domains, MySQL
    databases, FTP/SFTP accounts, cron jobs and files. Every tool takes it as
    `name` (`name: "shop"`). Results and older descriptions call the same value
    `username` or "user"; pass it back as `name`. A project record's own `name`
    field is an unused label. `mysql_user_*` (MySQL accounts) and `app_user_*`
    (accounts inside the deployed app) are not projects.

    Tool names are `<resource>_<action>`, never carrying parameters.

    Many tools change server state and some destroy data: deleting a project
    removes its container, files and databases; suspending takes its sites
    offline. Follow the annotations, and confirm destructive calls with the
    operator on a production server.

    Git on a project starts with `git_status`: `managed_by: deploy` means
    redeploy with `project_rebuild`, `site_git` means the git tools. `git_push`
    commits a dirty tree itself; confirm first.

    Only the everyday tools are listed. Find any other (MySQL, FTP, cron,
    backups, firewall, system settings, ...) with `search_tools`, e.g.
    `mysql user`, and run it with `execute_tools` using the exact name and
    arguments returned. Not found means the operator has not enabled it.
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
     * The constructor filters this and, with tool search on, regroups it under
     * ToolSearch::class (see ToolPolicy::layout()). The default value stays a
     * plain list, since ToolRegistry reads it.
     *
     * @var array<int|string, class-string<\Laravel\Mcp\Server\Tool>|array<int, class-string<\Laravel\Mcp\Server\Tool>>>
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

        // Last, so the catalogue holds exactly what the filters above left.
        $this->tools = (new ToolPolicy())->layout($this->tools);
    }
}
