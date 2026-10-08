<?php

namespace Tests\Unit\Mcp;

use App\Console\Commands\Api\McpConnectCommand;
use App\Mcp\ClientRegistration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;

use Tests\TestCase;

/**
 * `pae mcp:connect` with no argument turns the printed list into an arrow-key
 * choice - but only where a prompt can be answered. Everywhere else it
 * has to stay what it was: a list, and nothing minted.
 */
class McpConnectCommandTest extends TestCase
{
    /** The keys and labels, in the order the operator is offered them. */
    private const LABELS = [
        'claude' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'codex' => 'Codex',
        'chatgpt-desktop' => 'ChatGPT Desktop',
        'gemini' => 'Gemini CLI',
        'grok' => 'Grok Build',
        'opencode' => 'OpenCode',
        'vscode' => 'VS Code / Copilot',
        'cursor' => 'Cursor',
        'windsurf' => 'Windsurf',
        'pi' => 'Pi',
        'hermes' => 'Hermes',
        'openclaw' => 'OpenClaw',
    ];

    public function test_the_choice_is_exactly_the_clients_that_can_connect(): void
    {
        $this->assertSame(self::LABELS, ClientRegistration::labels());

        // Same keys, same order: the prompt cannot offer an agent the list does
        // not, nor the other way round.
        $this->assertSame(ClientRegistration::clients(), array_keys(ClientRegistration::labels()));
    }

    /**
     * The guard, tested where it decides. Prompts do not fail without a
     * terminal - `select()` returns its default - so an installer, a pipe or a
     * `--no-interaction` run would mint a token for Claude Code while looking
     * like it had only listed what it supports. This is an interactive input
     * with no tty behind it, which is the case that must not prompt.
     */
    public function test_an_interactive_input_without_a_terminal_still_does_not_ask(): void
    {
        $command = $this->app[Kernel::class]->all()['mcp:connect'];
        $this->assertInstanceOf(McpConnectCommand::class, $command);

        $command->setInput($input = new ArrayInput([], $command->getDefinition()));
        $this->assertTrue($input->isInteractive());
        $this->assertFalse(@stream_isatty(STDIN));

        $this->assertFalse($command->canAsk());
    }

    /** `--bare` is for scripts, so it lists rather than asks. */
    public function test_bare_never_asks(): void
    {
        $command = $this->app[Kernel::class]->all()['mcp:connect'];
        $this->assertInstanceOf(McpConnectCommand::class, $command);

        $command->setInput($input = new ArrayInput(['--bare' => true], $command->getDefinition()));
        $this->assertTrue($input->isInteractive());

        $this->assertFalse($command->canAsk());
    }

    /**
     * The fallback is the whole listing, one line per agent, and nothing is
     * minted: a script parsing this must be able to pick a client by name.
     */
    public function test_a_run_that_cannot_answer_gets_the_list_and_mints_nothing(): void
    {
        $this->withoutMockingConsoleOutput();

        $this->assertSame(0, Artisan::call('mcp:connect'));

        $output = Artisan::output();

        foreach (self::LABELS as $client => $label) {
            $this->assertStringContainsString(
                sprintf('  %-20s pae mcp:connect:%s', $label, $client),
                $output,
                "the listing must name the command for {$client}"
            );
        }

        $this->assertStringContainsString('Each one mints a new MCP token', $output);
        $this->assertStringNotContainsString('MCP token created', $output);
    }

    /** A typo fails before anything is minted, and names what does exist. */
    public function test_an_unknown_client_is_refused_without_minting(): void
    {
        $command = $this->artisan('mcp:connect', ['client' => 'not-a-client']);

        // The whole message on one line: the error names the alternatives, so
        // the reader can fix the typo without running the command again.
        $command->expectsOutputToContain(
            'Unknown client "not-a-client". Known clients: ' . implode(', ', ClientRegistration::clients()) . '.'
        );
        $command->doesntExpectOutputToContain('MCP token created');

        $command->assertExitCode(1);
    }

    public function test_windsurf_prints_the_devin_cli_command(): void
    {
        $entry = ClientRegistration::for('windsurf', 'https://example.test/mcp', 'tok');

        $this->assertSame('Windsurf', $entry['label']);
        $this->assertSame(
            [
                'devin plugins install panelalpha/agent-skills#engine',
                'devin mcp add -s user -H "Authorization: Bearer tok" panelalpha-engine https://example.test/mcp',
            ],
            $entry['lines']
        );
    }

    public function test_hermes_prints_the_cli_command(): void
    {
        $entry = ClientRegistration::for('hermes', 'https://example.test/mcp', 'tok');

        $this->assertSame('Hermes', $entry['label']);
        $this->assertSame(
            [
                'hermes plugins install panelalpha/agent-skills/engine --enable',
                "echo \"MCP_PANELALPHA_ENGINE_API_KEY='tok'\" >> ~/.hermes/.env",
                'hermes mcp add panelalpha-engine --url https://example.test/mcp --auth header',
            ],
            $entry['lines']
        );
    }

    public function test_openclaw_prints_the_streamable_http_command(): void
    {
        $entry = ClientRegistration::for('openclaw', 'https://example.test/mcp', 'tok');

        $this->assertSame('OpenClaw', $entry['label']);
        $this->assertStringContainsString('panelalpha/agent-skills', $entry['note'] ?? '');
        $this->assertSame(
            [
                'openclaw plugins install engine --marketplace panelalpha/agent-skills',
                'openclaw mcp add panelalpha-engine --url https://example.test/mcp --transport streamable-http --header "Authorization: Bearer tok"',
            ],
            $entry['lines']
        );
    }

    public function test_pi_prints_an_http_json_block(): void
    {
        $entry = ClientRegistration::for('pi', 'https://example.test/mcp', 'tok');

        $this->assertSame('Pi', $entry['label']);
        $this->assertStringContainsString('pi install npm:pi-mcp-adapter', $entry['note'] ?? '');
        $this->assertStringContainsString('panelalpha/agent-skills', $entry['note'] ?? '');
        $this->assertSame('https://example.test/mcp', $this->jsonUrl($entry['lines']));
        $this->assertSame('Bearer tok', $this->jsonAuthorization($entry['lines']));
    }

    /** @param array<int, string> $lines */
    private function jsonUrl(array $lines): string
    {
        $decoded = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($decoded);

        return $decoded['mcpServers']['panelalpha-engine']['url'];
    }

    /** @param array<int, string> $lines */
    private function jsonAuthorization(array $lines): string
    {
        $decoded = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($decoded);

        return $decoded['mcpServers']['panelalpha-engine']['headers']['Authorization'];
    }
}
