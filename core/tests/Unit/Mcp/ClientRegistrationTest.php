<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ClientRegistration;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The printed line is the whole point: an operator pastes it verbatim, so a
 * missing token, a wrong URL or a client's own header spelling breaks setup
 * silently.
 */
class ClientRegistrationTest extends TestCase
{
    private const URL = 'https://198.51.100.7:2011/mcp';
    private const TOKEN = '12|abcdef';

    public function test_every_client_gets_a_snippet_carrying_the_endpoint_and_the_token(): void
    {
        foreach (ClientRegistration::all(self::URL, self::TOKEN) as $key => $entry) {
            $snippet = implode("\n", $entry['lines']);

            $this->assertNotSame('', trim($entry['label']), "{$key} needs a label");
            $this->assertStringContainsString(self::URL, $snippet, "{$key} must print the endpoint");
            $this->assertStringContainsString(self::TOKEN, $snippet, "{$key} must print the token");
        }
    }

    public function test_the_clients_operators_actually_use_are_covered(): void
    {
        $this->assertSame(
            [
                'claude',
                'claude-desktop',
                'codex',
                'chatgpt-desktop',
                'gemini',
                'grok',
                'opencode',
                'vscode',
                'cursor',
                'windsurf',
                'pi',
                'hermes',
                'openclaw',
            ],
            ClientRegistration::clients()
        );
    }

    /** Each CLI spells the header differently; pasting one into another fails. */
    public function test_each_client_keeps_its_own_argv_shape(): void
    {
        $line = fn (string $client): string => implode(
            "\n",
            ClientRegistration::for($client, self::URL, self::TOKEN)['lines']
        );

        $this->assertStringContainsString('claude plugin marketplace add ' . ClientRegistration::SKILLS_REPO, $line('claude'));
        $this->assertStringContainsString('--config "api_token=' . self::TOKEN . '"', $line('claude'));
        $this->assertStringContainsString('install engine@panelalpha', $line('claude'));
        $this->assertStringContainsString('codex plugin marketplace add ' . ClientRegistration::SKILLS_REPO, $line('codex'));
        $this->assertStringContainsString('codex plugin add engine@panelalpha', $line('codex'));
        $this->assertStringContainsString('--bearer-token-env-var PANELALPHA_MCP_TOKEN', $line('codex'));
        $this->assertStringContainsString('grok plugin marketplace add ' . ClientRegistration::SKILLS_REPO, $line('grok'));
        $this->assertStringContainsString('grok plugin install engine --trust', $line('grok'));
        $this->assertStringContainsString('devin plugins install ' . ClientRegistration::SKILLS_REPO . '#engine', $line('windsurf'));
        $this->assertStringContainsString('--header "Authorization=Bearer ' . self::TOKEN . '"', $line('opencode'));
        $this->assertStringContainsString('code --add-mcp', $line('vscode'));
        $this->assertStringContainsString('openclaw plugins install engine --marketplace ' . ClientRegistration::SKILLS_REPO, $line('openclaw'));
        $this->assertStringContainsString('--transport streamable-http', $line('openclaw'));
        $this->assertStringContainsString('--header "Authorization: Bearer ' . self::TOKEN . '"', $line('openclaw'));
        $this->assertStringContainsString('hermes plugins install ' . ClientRegistration::SKILLS_REPO . '/engine --enable', $line('hermes'));
        $this->assertStringContainsString("MCP_PANELALPHA_ENGINE_API_KEY='" . self::TOKEN . "'", $line('hermes'));
        $this->assertStringContainsString('hermes mcp add panelalpha-engine --url ' . self::URL . ' --auth header', $line('hermes'));
    }

    /** `&&` is a bashism; Windows PowerShell 5.1 cannot run a pasted line that uses it. */
    public function test_printed_commands_do_not_chain_with_and(): void
    {
        foreach (ClientRegistration::all(self::URL, self::TOKEN) as $key => $entry) {
            foreach ($entry['lines'] as $i => $text) {
                $this->assertStringNotContainsString(
                    ' && ',
                    $text,
                    "{$key} line {$i} chains with &&, which PowerShell 5.1 cannot run"
                );
                $this->assertStringNotContainsString(
                    '&& \\',
                    $text,
                    "{$key} line {$i} continues with && \\, which PowerShell cannot run"
                );
            }
        }
    }

    /**
     * Clients without a pasteable CLI get a sentence to paste into chat, not a
     * command line. It still has to carry the endpoint and the token: the
     * assistant does the typing.
     */
    public function test_the_desktop_apps_get_a_verified_prompt(): void
    {
        $prompt = fn (string $client): string => implode(
            "\n",
            ClientRegistration::for($client, self::URL, self::TOKEN)['lines']
        );

        $claude = $prompt('claude-desktop');
        $this->assertStringContainsString('Add the GitHub marketplace ' . ClientRegistration::CLAUDE_MARKETPLACE, $claude);
        $this->assertStringContainsString('install engine@panelalpha', $claude);
        $this->assertStringContainsString('server_url=' . self::URL, $claude);
        $this->assertStringContainsString('api_token=' . self::TOKEN, $claude);
        $this->assertStringContainsString('then list the projects on this engine', $claude);

        $chatgpt = $prompt('chatgpt-desktop');
        $this->assertStringContainsString('Add the GitHub marketplace ' . ClientRegistration::CLAUDE_MARKETPLACE, $chatgpt);
        $this->assertStringContainsString('install engine@panelalpha', $chatgpt);
        $this->assertStringContainsString('PANELALPHA_MCP_TOKEN=' . self::TOKEN, $chatgpt);
        $this->assertStringContainsString(
            'codex mcp add panelalpha-engine --url ' . self::URL . ' --bearer-token-env-var PANELALPHA_MCP_TOKEN',
            $chatgpt
        );
        $this->assertStringContainsString('then list the projects on this engine', $chatgpt);

        // A prompt is not a command: neither may be mistaken for something the
        // operator can run in a shell on the server.
        $this->assertStringNotContainsString("claude plugin marketplace add", $claude);
        $this->assertStringNotContainsString("PANELALPHA_MCP_TOKEN='" . self::TOKEN . "' codex", $chatgpt);
    }

    /**
     * Cursor has no `mcp add`, so the operator chooses: a prompt like Claude
     * Desktop, or the JSON they would type into Settings themselves.
     */
    public function test_cursor_prints_a_prompt_and_a_json_block(): void
    {
        $lines = ClientRegistration::for('cursor', self::URL, self::TOKEN)['lines'];
        $text = implode("\n", $lines);

        $this->assertStringContainsString('Paste into a Cursor Agent chat (plugin and MCP):', $text);
        $this->assertStringContainsString('Add the GitHub marketplace ' . ClientRegistration::SKILLS_REPO, $text);
        $this->assertStringContainsString('install engine', $text);
        $this->assertStringContainsString('url=' . self::URL, $text);
        $this->assertStringContainsString('Authorization: Bearer ' . self::TOKEN, $text);
        $this->assertStringContainsString('then list the projects on this engine', $text);
        $this->assertStringContainsString('Settings -> Tools & MCP', $text);
        $this->assertStringContainsString('~/.cursor/mcp.json', $text);
        $this->assertStringContainsString('Then install the engine plugin', $text);
        $this->assertStringContainsString('Customize -> Plugins -> import https://github.com/' . ClientRegistration::SKILLS_REPO, $text);
        $this->assertStringNotContainsString('cursor --add-mcp', $text);

        $start = array_search('{', $lines, true);
        $this->assertNotFalse($start, 'the JSON block must be in the printed lines');
        $end = $start;
        foreach (array_slice($lines, $start) as $offset => $line) {
            if ($line === '}') {
                $end = $start + $offset;
                break;
            }
        }
        $config = json_decode(implode("\n", array_slice($lines, $start, $end - $start + 1)), true);
        $this->assertSame(self::URL, $config['mcpServers'][ClientRegistration::SERVER_NAME]['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $config['mcpServers'][ClientRegistration::SERVER_NAME]['headers']['Authorization']);
    }

    public function test_an_unknown_client_names_the_ones_that_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('claude');

        ClientRegistration::for('not-a-client', self::URL, self::TOKEN);
    }
}
