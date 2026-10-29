<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ClientRegistration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The printed line is the whole point: an operator pastes it verbatim, so a
 * missing token, a wrong URL or a client's own header spelling breaks setup
 * silently.
 */
class ClientRegistrationTest extends TestCase
{
    private const TOKEN = '12|abcdef';

    public function test_every_client_gets_a_snippet_carrying_the_endpoint_and_the_token(): void
    {
        foreach (ClientRegistration::all(self::url(), self::TOKEN) as $key => $entry) {
            $snippet = implode("\n", $entry['lines']);

            $this->assertNotSame('', trim($entry['label']), "{$key} needs a label");
            $this->assertStringContainsString(self::url(), $snippet, "{$key} must print the endpoint");
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
            ClientRegistration::for($client, self::url(), self::TOKEN)['lines']
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
        $this->assertStringContainsString('hermes mcp add panelalpha-engine --url ' . self::url() . ' --auth header', $line('hermes'));
    }

    /** `&&` is a bashism; Windows PowerShell 5.1 cannot run a pasted line that uses it. */
    public function test_printed_commands_do_not_chain_with_and(): void
    {
        foreach (ClientRegistration::all(self::url(), self::TOKEN) as $key => $entry) {
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
            ClientRegistration::for($client, self::url(), self::TOKEN)['lines']
        );

        $claude = $prompt('claude-desktop');
        $this->assertStringContainsString('Add the GitHub marketplace ' . ClientRegistration::CLAUDE_MARKETPLACE, $claude);
        $this->assertStringContainsString('install engine@panelalpha', $claude);
        $this->assertStringContainsString('server_url=' . self::url(), $claude);
        $this->assertStringContainsString('api_token=' . self::TOKEN, $claude);
        $this->assertStringContainsString('then list the projects on this engine', $claude);

        $chatgpt = $prompt('chatgpt-desktop');
        $this->assertStringContainsString('Add the GitHub marketplace ' . ClientRegistration::CLAUDE_MARKETPLACE, $chatgpt);
        $this->assertStringContainsString('install engine@panelalpha', $chatgpt);
        $this->assertStringContainsString('PANELALPHA_MCP_TOKEN=' . self::TOKEN, $chatgpt);
        $this->assertStringContainsString(
            'codex mcp add panelalpha-engine --url ' . self::url() . ' --bearer-token-env-var PANELALPHA_MCP_TOKEN',
            $chatgpt
        );
        $this->assertStringContainsString('then list the projects on this engine', $chatgpt);

        // A prompt is not a command: neither may be mistaken for something the
        // operator can run in a shell on the server.
        $this->assertStringNotContainsString("claude plugin marketplace add", $claude);
        $this->assertStringNotContainsString("PANELALPHA_MCP_TOKEN='" . self::TOKEN . "' codex", $chatgpt);
    }

    public function test_cursor_prints_an_install_link_for_one_server(): void
    {
        $lines = ClientRegistration::for('cursor', self::url(), self::TOKEN)['lines'];
        $links = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'cursor://')));
        $this->assertCount(1, $links);

        $this->assertSame(
            ['url' => self::url(), 'headers' => ['Authorization' => 'Bearer ' . self::TOKEN]],
            $this->cursorLinkConfig($links[0])
        );
        $this->assertLessThan(array_search('{', $lines, true), array_search($links[0], $lines, true));
    }

    public function test_cursor_install_link_round_trips_url_and_token_characters(): void
    {
        // These inputs produce +, / and = in base64: unescaped query values
        // would turn + into a space before Cursor decodes the configuration.
        $url = 'https://example.test:2011/mcp?tenant=żółć&path="quoted"';
        foreach (['12|token+/?=&"\\', '12|>>>???', '12|żółć'] as $token) {
            $lines = ClientRegistration::for('cursor', $url, $token)['lines'];
            $links = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'cursor://')));
            $this->assertCount(1, $links);
            $this->assertStringNotContainsString('+', $links[0]);
            $this->assertSame(
                ['url' => $url, 'headers' => ['Authorization' => 'Bearer ' . $token]],
                $this->cursorLinkConfig($links[0])
            );
        }
    }

    public function test_cursor_chat_prompt_installs_only_the_plugin_without_credentials(): void
    {
        $lines = ClientRegistration::for('cursor', self::url(), self::TOKEN)['lines'];
        $prompts = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'Add the GitHub marketplace')));
        $this->assertCount(1, $prompts);
        $this->assertSame(
            'Add the GitHub marketplace ' . ClientRegistration::SKILLS_REPO . ' and install engine.',
            $prompts[0]
        );
        $this->assertStringNotContainsString(self::url(), $prompts[0]);
        $this->assertStringNotContainsString(self::TOKEN, $prompts[0]);
        $this->assertStringNotContainsString('mcp.json', $prompts[0]);
    }

    public function test_cursor_keeps_manual_json_and_plugin_installation(): void
    {
        $lines = ClientRegistration::for('cursor', self::url(), self::TOKEN)['lines'];
        $text = implode("\n", $lines);

        $this->assertStringContainsString('Settings -> Tools & MCP', $text);
        $this->assertStringContainsString('~/.cursor/mcp.json', $text);
        $this->assertStringContainsString('alongside existing servers', $text);
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
        $config = json_decode(implode("\n", array_slice($lines, $start, $end - $start + 1)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::url(), $config['mcpServers'][ClientRegistration::SERVER_NAME]['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $config['mcpServers'][ClientRegistration::SERVER_NAME]['headers']['Authorization']);
    }

    /** @return array{url: string, headers: array{Authorization: string}} */
    private function cursorLinkConfig(string $link): array
    {
        $this->assertSame('cursor', parse_url($link, PHP_URL_SCHEME));
        $this->assertSame('anysphere.cursor-deeplink', parse_url($link, PHP_URL_HOST));
        $this->assertSame('/mcp/install', parse_url($link, PHP_URL_PATH));
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame(['name', 'config'], array_keys($query));
        $this->assertSame(ClientRegistration::SERVER_NAME, $query['name']);
        $json = base64_decode($query['config'], true);
        $this->assertNotFalse($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_an_unknown_client_names_the_ones_that_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('claude');

        ClientRegistration::for('not-a-client', self::url(), self::TOKEN);
    }

    private static function url(): string
    {
        return 'https://' . TestNetwork::public2(7) . ':2011/mcp';
    }
}
