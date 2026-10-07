<?php

namespace App\Mcp;

use InvalidArgumentException;

/**
 * Every MCP client spells registration differently, and getting it wrong costs
 * the operator a search. Print the exact command for each one instead.
 */
class ClientRegistration
{
    /** GitHub repo holding the `engine` plugin: deploy/debug skills, plus MCP where the client can take URL and token on install. */
    public const SKILLS_REPO = 'panelalpha/agent-skills';

    /** Claude Code's marketplace id is this repo. */
    public const CLAUDE_MARKETPLACE = self::SKILLS_REPO;

    /** One name on every client, so the engine's server never collides with another PanelAlpha product's. */
    public const SERVER_NAME = 'panelalpha-engine';

    /**
     * @return array<string, array{label: string, lines: array<int, string>, note?: string}>
     */
    public static function all(string $url, string $token): array
    {
        return [
            'claude' => [
                'label' => 'Claude Code',
                'note' => 'installs the PanelAlpha Engine plugin: this MCP server plus project create and debug skills',
                // Quoted: a Sanctum token carries a `|`, which the shell reads as a pipe.
                // Separate lines, not `&&`: Windows PowerShell 5.1 cannot chain with it.
                'lines' => [
                    sprintf('claude plugin marketplace add %s', self::SKILLS_REPO),
                    sprintf(
                        'claude plugin install engine@panelalpha --config "server_url=%s" --config "api_token=%s"',
                        $url,
                        $token
                    ),
                ],
            ],
            'claude-desktop' => [
                'label' => 'Claude Desktop',
                // The desktop app runs Claude Code itself, so the same install is
                // asked for in a sentence instead of on a command line.
                'note' => 'a prompt to paste into Claude Desktop, whose Claude Code mode does the installing',
                'lines' => [
                    sprintf(
                        'Add the GitHub marketplace %s and install engine@panelalpha with server_url=%s'
                        . ' and api_token=%s. Confirm panelalpha-engine is connected, then list the projects on this engine.',
                        self::SKILLS_REPO,
                        $url,
                        $token
                    ),
                ],
            ],
            'codex' => [
                'label' => 'Codex',
                // Plugin install has no --config, so skills and MCP are separate lines.
                // Codex reads the token from the environment; it has no inline form.
                'note' => 'first two lines install the create/debug skills from ' . self::SKILLS_REPO,
                'lines' => [
                    sprintf('codex plugin marketplace add %s', self::SKILLS_REPO),
                    'codex plugin add engine@panelalpha',
                    sprintf(
                        "PANELALPHA_MCP_TOKEN='%s' codex mcp add panelalpha-engine --url %s --bearer-token-env-var PANELALPHA_MCP_TOKEN",
                        $token,
                        $url
                    ),
                ],
            ],
            'chatgpt-desktop' => [
                'label' => 'ChatGPT Desktop',
                // ChatGPT Desktop runs Codex itself, so the same two steps are
                // asked for in a sentence instead of on a command line.
                'note' => 'a prompt to paste into ChatGPT Desktop, whose Codex mode runs the command for you',
                'lines' => [
                    sprintf(
                        'Add the GitHub marketplace %s and install engine@panelalpha. Then set'
                        . ' PANELALPHA_MCP_TOKEN=%s and run: codex mcp add panelalpha-engine --url %s'
                        . ' --bearer-token-env-var PANELALPHA_MCP_TOKEN. Confirm panelalpha-engine is connected,'
                        . ' then list the projects on this engine.',
                        self::SKILLS_REPO,
                        $token,
                        $url
                    ),
                ],
            ],
            'gemini' => [
                'label' => 'Gemini CLI',
                'lines' => [
                    sprintf('gemini mcp add --transport http --header "Authorization: Bearer %s" panelalpha-engine %s', $token, $url),
                ],
            ],
            'grok' => [
                'label' => 'Grok Build',
                'note' => 'first two lines install the create/debug skills from ' . self::SKILLS_REPO,
                'lines' => [
                    sprintf('grok plugin marketplace add %s', self::SKILLS_REPO),
                    'grok plugin install engine --trust',
                    sprintf('grok mcp add --transport http panelalpha-engine %s --header "Authorization: Bearer %s"', $url, $token),
                ],
            ],
            'opencode' => [
                'label' => 'OpenCode',
                // --header takes KEY=VALUE here, not "KEY: VALUE".
                'note' => 'skills: point opencode.json at ' . self::SKILLS_REPO
                    . '/engine/opencode.js (the npm package is not published yet)',
                'lines' => [
                    sprintf('opencode mcp add panelalpha-engine --url %s --header "Authorization=Bearer %s"', $url, $token),
                ],
            ],
            'vscode' => [
                'label' => 'VS Code / Copilot',
                'lines' => [
                    sprintf(
                        'code --add-mcp \'{"name":"panelalpha-engine","type":"http","url":"%s","headers":{"Authorization":"Bearer %s"}}\'',
                        $url,
                        $token
                    ),
                ],
            ],
            'cursor' => [
                'label' => 'Cursor',
                // Cursor has no `mcp add` (and `cursor --add-mcp` is ignored).
                // Three pasteable blocks: a prompt that does plugin + MCP, or
                // JSON for MCP plus the plugin UI (skills only; mcp-none.json).
                'note' => 'a prompt to paste into a Cursor Agent chat, or JSON for MCP plus the engine plugin from ' . self::SKILLS_REPO,
                'lines' => array_merge(
                    [
                        'Paste into a Cursor Agent chat (plugin and MCP):',
                        sprintf(
                            'Add the GitHub marketplace %s and install engine. Then add an MCP server named panelalpha-engine to ~/.cursor/mcp.json (all projects, not a project file) with url=%s and header Authorization: Bearer %s. If that file already has other servers, add panelalpha-engine alongside them. Confirm panelalpha-engine is connected, then list the projects on this engine.',
                            self::SKILLS_REPO,
                            $url,
                            $token
                        ),
                        '',
                        'Or add MCP yourself in Settings -> Tools & MCP -> New MCP Server (or ~/.cursor/mcp.json):',
                    ],
                    self::httpMcpJsonLines($url, $token),
                    [
                        '',
                        'Then install the engine plugin (create and debug skills):',
                        'Customize -> Plugins -> import https://github.com/' . self::SKILLS_REPO . ' and install engine',
                    ],
                ),
            ],
            'windsurf' => [
                'label' => 'Windsurf',
                // Current Windsurf (Devin Local) registers through the Devin CLI.
                // Cascade still reads ~/.codeium/windsurf/mcp_config.json; that
                // path is on the operator page, not printed here.
                'note' => 'first line installs the create/debug skills from ' . self::SKILLS_REPO . '#engine',
                'lines' => [
                    sprintf('devin plugins install %s#engine', self::SKILLS_REPO),
                    sprintf(
                        'devin mcp add -s user -H "Authorization: Bearer %s" panelalpha-engine %s',
                        $token,
                        $url
                    ),
                ],
            ],
            'pi' => [
                'label' => 'Pi',
                'note' => 'skills: pi install git:github.com/' . self::SKILLS_REPO
                    . '. Install the adapter once: pi install npm:pi-mcp-adapter, then paste into'
                    . ' ~/.config/mcp/mcp.json (all projects) or .mcp.json (this project)',
                'lines' => self::httpMcpJsonLines($url, $token),
            ],
            'hermes' => [
                'label' => 'Hermes',
                // --auth header is required. Hermes has no --header: it reads
                // the token from ~/.hermes/.env as MCP_PANELALPHA_ENGINE_API_KEY.
                'note' => 'first two lines install the create/debug skills and write the token;'
                    . ' hermes mcp add still asks whether to enable every tool',
                'lines' => [
                    sprintf('hermes plugins install %s/engine --enable', self::SKILLS_REPO),
                    sprintf("echo \"MCP_PANELALPHA_ENGINE_API_KEY='%s'\" >> ~/.hermes/.env", $token),
                    sprintf('hermes mcp add panelalpha-engine --url %s --auth header', $url),
                ],
            ],
            'openclaw' => [
                'label' => 'OpenClaw',
                // --transport streamable-http is required; omitting it is SSE or stdio.
                'note' => 'first line installs the create/debug skills from the ' . self::SKILLS_REPO . ' marketplace',
                'lines' => [
                    sprintf('openclaw plugins install engine --marketplace %s', self::SKILLS_REPO),
                    sprintf(
                        'openclaw mcp add panelalpha-engine --url %s --transport streamable-http --header "Authorization: Bearer %s"',
                        $url,
                        $token
                    ),
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function clients(): array
    {
        return array_keys(self::all('', ''));
    }

    /**
     * Every client by key and label, in the order they should be offered.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::all('', '') as $key => $entry) {
            $labels[$key] = $entry['label'];
        }

        return $labels;
    }

    /**
     * @return array{label: string, lines: array<int, string>, note?: string}
     */
    public static function for(string $client, string $url, string $token): array
    {
        $all = self::all($url, $token);
        $key = strtolower(trim($client));

        if (!isset($all[$key])) {
            throw new InvalidArgumentException(
                sprintf('Unknown client "%s". Known clients: %s.', $client, implode(', ', self::clients()))
            );
        }

        return $all[$key];
    }

    /**
     * The JSON Cursor and Pi paste into their settings file. Same shape on
     * both, so a token or URL fix cannot land on only one of them.
     *
     * @return array<int, string>
     */
    private static function httpMcpJsonLines(string $url, string $token): array
    {
        return [
            '{',
            '  "mcpServers": {',
            '    "' . self::SERVER_NAME . '": {',
            sprintf('      "url": "%s",', $url),
            '      "headers": {',
            sprintf('        "Authorization": "Bearer %s"', $token),
            '      }',
            '    }',
            '  }',
            '}',
        ];
    }
}
