<?php

namespace App\System\Firewall;

/**
 * What the CSF API's tools and routes are called now that the firewall API
 * replaced them, for settings and tokens that still name the old ones.
 *
 * Only known names are renamed. One with no successor (csf_ui_credentials,
 * GET /csf/ui-credentials) is left as it is: it matches nothing, and dropping
 * it could empty a token's list of limits, which would mean "no limit".
 */
final class CsfRenames
{
    public const TOOLS = [
        'csf_rule_list' => 'firewall_rule_list',
        'csf_rule_create' => 'firewall_rule_create',
        'csf_rule_update' => 'firewall_rule_update',
        'csf_rule_delete' => 'firewall_rule_delete',
        'csf_status' => 'firewall_status',
        'csf_restart' => 'firewall_reload',
        'csf_enable' => 'firewall_enable',
        'csf_disable' => 'firewall_disable',
    ];

    public const TOOLSETS = ['csf' => 'firewall'];

    public const ROUTES = [
        'GET /csf/rules' => 'GET /firewall/rules',
        'POST /csf/rules/{type}' => 'POST /firewall/rules',
        'PUT /csf/rules/{type}/{lineMd5}' => 'PUT /firewall/rules/{id}',
        'DELETE /csf/rules/{type}/{lineMd5}' => 'DELETE /firewall/rules/{id}',
        'GET /csf/status' => 'GET /firewall/status',
        'PUT /csf/restart' => 'PUT /firewall/reload',
        'PUT /csf/enable' => 'PUT /firewall/enable',
        'PUT /csf/disable' => 'PUT /firewall/disable',
    ];

    /** A tool name or a `csf_*` style pattern. */
    public static function tool(string $name): string
    {
        $name = trim($name);
        if (isset(self::TOOLS[$name])) {
            return self::TOOLS[$name];
        }
        // A pattern: csf_* and csf_rule_* keep matching what they matched.
        if (str_contains($name, '*') && str_starts_with($name, 'csf_')) {
            return 'firewall_' . substr($name, strlen('csf_'));
        }

        return $name;
    }

    /** A Sanctum ability: `mcp:<tool>` or `api:<VERB /path>`. */
    public static function ability(string $ability): string
    {
        if (str_starts_with($ability, 'mcp:')) {
            return 'mcp:' . self::tool(substr($ability, 4));
        }
        if (str_starts_with($ability, 'api:')) {
            $route = substr($ability, 4);

            return 'api:' . (self::ROUTES[$route] ?? $route);
        }

        return $ability;
    }

    /** A comma-separated list of tools (MCP_TOOLS, MCP_DENIED_TOOLS, MCP_DIRECT_TOOLS). */
    public static function toolList(string $csv): string
    {
        return self::mapList($csv, self::tool(...));
    }

    /** MCP_TOOLSETS. */
    public static function toolsetList(string $csv): string
    {
        return self::mapList($csv, static fn (string $t): string => self::TOOLSETS[strtolower(trim($t))] ?? trim($t));
    }

    /** MCP_DENIED_TOOLS_REGEX: csf_restart is firewall_reload, every other csf name keeps its suffix. */
    public static function regex(string $regex): string
    {
        return str_replace('csf', 'firewall', str_replace('csf_restart', 'firewall_reload', $regex));
    }

    /** @param callable(string): string $map */
    private static function mapList(string $csv, callable $map): string
    {
        if (!str_contains(strtolower($csv), 'csf')) {
            return $csv;
        }

        return implode(',', array_map($map, explode(',', $csv)));
    }
}
