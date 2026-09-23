<?php

namespace App\Console\Commands\Api;

use App\Auth\TokenAbilities;
use App\Console\Commands\Concerns\MintsTokens;
use App\Mcp\ClientRegistration;
use App\Models\Admin;
use Illuminate\Console\Command;
use InvalidArgumentException;

class McpTokensCreateCommand extends Command
{
    use MintsTokens;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['mcp-tokens:create'];

    protected $signature = 'mcp:token:create
                            {name}
                            {--s|short}
                            {--no-register}
                            {--client= : Print the registration command for one client only}
                            {--api : Also let this token call the REST API directly}
                            {--expires= : How long it lasts, e.g. 90d, 12h; omit for never}';

    protected $description = 'Create a token for an AI assistant';

    public function handle(): int
    {
        $root = Admin::rootAccount();
        $name = $this->argument('name');
        if (!is_string($name)) {
            $this->error("Invalid 'name' argument");
            return 1;
        }

        $client = $this->option('client');
        if ($client !== null && !is_string($client)) {
            $this->error("Invalid 'client' option");
            return 1;
        }

        $expiry = $this->expiry();

        if ($expiry === false) {
            return 1;
        }

        // `mcp` alone: this token speaks MCP, and is refused at /api unless
        // the operator asked for that too. Tool calls still reach the API —
        // they arrive as this engine's own dispatch, which EnsureTokenMayUseApi
        // can tell apart from a caller presenting the same bearer.
        $token = $root->createToken(
            $name,
            TokenAbilities::build(api: (bool) $this->option('api'), mcp: true),
            $expiry
        );

        if ($this->option('short')) {
            $this->line($token->plainTextToken);
            return 0;
        }

        $this->info(sprintf('MCP token created (%s).', $this->lifetime($expiry)));
        $this->warn("Save it — it won't be retrievable again.");
        $this->comment('=============================');
        $this->line($token->plainTextToken);
        $this->comment('=============================');

        if (!$this->option('no-register')) {
            try {
                $this->registrationHint($token->plainTextToken, $client);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return 1;
            }
        }

        return 0;
    }

    /**
     * The token on its own is not enough to connect: a client also needs the
     * endpoint, the transport and - on a self-signed install - the CA. Print
     * the whole command, for every client, rather than leave the operator to
     * translate one client's argv into another's.
     */
    private function registrationHint(string $token, ?string $client): void
    {
        $url = rtrim((string) config('app.url'), '/') . '/mcp';

        $entries = $client === null || $client === ''
            ? ClientRegistration::all($url, $token)
            : [ClientRegistration::for($client, $url, $token)];

        $single = $client !== null && $client !== '';

        if (!$single) {
            $this->newLine();
            $this->info('Register the MCP server with your client:');
        }

        foreach ($entries as $entry) {
            $this->newLine();
            $this->line('<options=bold>' . $entry['label'] . '</>');
            foreach ($entry['lines'] as $line) {
                $this->line($line);
            }
        }

        if (!is_file('/etc/letsencrypt/live/panelalpha-engine-ip-cert/fullchain.pem')) {
            $this->newLine();
            $this->warn('The engine is using a self-signed certificate. Copy');
            $this->warn('/opt/panelalpha/shared-hosting/crt/server.cert to the client machine and trust it -');
            $this->warn('for the Node-based clients (Claude Code, Gemini CLI, VS Code, Cursor, Windsurf, Pi) export');
            $this->warn('NODE_EXTRA_CA_CERTS=/path/to/server.cert in every shell that starts them -');
            $this->warn('otherwise the server registers but fails to connect.');
        }

        $this->newLine();
        $this->line(sprintf('Verify the endpoint with: pae mcp:check %s', $token));
    }
}
