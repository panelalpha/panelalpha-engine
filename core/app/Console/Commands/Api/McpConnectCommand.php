<?php

namespace App\Console\Commands\Api;

use App\Mcp\ClientRegistration;
use App\Console\Prompts\PanelAlphaTheme;
use App\Models\Admin;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;

/**
 * `pae mcp:connect` offers the AI agents to choose from; `pae mcp:connect:<agent>`
 * (routes/console.php) mints a token and prints that agent's command. Both are
 * also reachable as `pae connect` and `pae connect <agent>`, because the agent
 * connecting is what the operator is doing and `mcp:` is our vocabulary.
 */
class McpConnectCommand extends Command
{
    /** `pae connect`: the obvious spelling, without the `mcp:` namespace. */
    protected $aliases = ['connect'];

    protected $signature = 'mcp:connect
        {client? : claude, claude-desktop, codex, chatgpt-desktop, gemini, grok, opencode, vscode, cursor, windsurf, pi, hermes or openclaw; omit to choose}
        {--bare : Print only the command, for scripts such as the installer}';

    protected $description = 'Choose the AI agent that should connect to this engine, or list them';

    public function handle(): int
    {
        $client = $this->argument('client');
        if (!is_string($client) || $client === '') {
            // With a terminal to answer on, the list becomes a choice: the
            // agent is picked with the arrow keys and the command printed, so
            // the operator never retypes a `pae mcp:connect:<agent>` by hand.
            $chosen = $this->chooseClient();
            if ($chosen === null) {
                return $this->listClients();
            }
            $client = $chosen;
        }
        $client = strtolower(trim($client));

        // Checked before a token exists, so a typo does not leave one behind.
        if (!in_array($client, ClientRegistration::clients(), true)) {
            $this->error(sprintf(
                'Unknown client "%s". Known clients: %s.',
                $client,
                implode(', ', ClientRegistration::clients())
            ));
            return 1;
        }

        if (!$this->option('bare')) {
            return $this->call('mcp:token:create', ['name' => $client, '--client' => $client]);
        }

        $token = Admin::rootAccount()->createToken($client, ['mcp'])->plainTextToken;
        $url   = rtrim((string) config('app.url'), '/') . '/mcp';
        foreach (ClientRegistration::for($client, $url, $token)['lines'] as $line) {
            $this->line($line);
        }

        return 0;
    }

    /**
     * The agent the operator picked, or null when nobody is there to pick one
     * and the list should be printed instead.
     */
    private function chooseClient(): ?string
    {
        if (!$this->canAsk()) {
            return null;
        }

        // Before the prompt exists, not after: a renderer is chosen in the
        // prompt's constructor, from whatever theme is active at that moment.
        PanelAlphaTheme::register();

        $labels = ClientRegistration::labels();

        $choice = select(
            label: 'Connect an AI agent to this engine',
            options: $labels,
            default: ClientRegistration::clients()[0],
            scroll: count($labels),
            hint: 'Arrow keys to choose, Enter to connect',
            info: static fn (string $key): string => sprintf('pae mcp:connect:%s', $key),
        );

        return is_string($choice) ? $choice : null;
    }

    /**
     * Whether a prompt can be answered here at all.
     *
     * `Laravel\Prompts\select()` does not fail without a terminal: it returns its
     * default. So an installer, a pipe or a `--no-interaction` run would silently
     * mint a token for the first agent instead of saying which agents exist.
     * `--bare` exists to be scripted, so it never asks either.
     */
    public function canAsk(): bool
    {
        return !$this->option('bare')
            && $this->input->isInteractive()
            && self::hasTerminal();
    }

    /** Prompts need a real terminal; a redirected stdin answers nothing. */
    private static function hasTerminal(): bool
    {
        return defined('STDIN') && @stream_isatty(STDIN);
    }

    private function listClients(): int
    {
        $this->line('Connect an AI agent to this engine. Run the command for yours:');
        $this->newLine();
        foreach (ClientRegistration::all('', '') as $key => $entry) {
            $this->line(sprintf('  %-20s pae mcp:connect:%s', $entry['label'], $key));
        }
        $this->newLine();
        $this->line('Each one mints a new MCP token and prints the exact command to run on your machine.');

        return 0;
    }
}
