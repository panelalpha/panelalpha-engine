<?php

namespace App\Console\Commands\Mcp;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A token that exists is not the same as a token that connects: TLS, the auth
 * chain and the JSON-RPC handshake each fail differently, and a client's `mcp
 * add` reports none of them - it writes its config without ever opening a
 * connection. So prove the endpoint end to end here, over real HTTP, the same
 * way the client will reach it.
 */
class CheckCommand extends Command
{
    // The token as an argument still works, but it shows in `ps` and in shell
    // history: prompt for it, or read it from stdin.
    protected $signature = 'mcp:check
        {token? : Deprecated - visible in ps and shell history. Omit it to be prompted}
        {--stdin : Read the token from standard input}';

    protected $description = 'Verify the MCP endpoint: TLS, token, auth enforcement and handshake';

    private const LETSENCRYPT_CERT = '/etc/letsencrypt/live/panelalpha-engine-ip-cert/fullchain.pem';
    private const SELF_SIGNED_CERT = '/opt/panelalpha/shared-hosting/crt/server.cert';

    private bool $ok = true;

    public function handle(): int
    {
        $token = $this->token();
        if ($token === null) {
            $this->error('No token given. Run `pae mcp:check` and paste it at the prompt, '
                . 'or pipe it in: `printf %s "$TOKEN" | pae mcp:check --stdin`.');
            return 1;
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            $this->error('APP_URL is not set, so there is no endpoint to check.');
            return 1;
        }

        // Verify against the engine's own certificate when it is self-signed,
        // and against the system store once Let's Encrypt has taken over. Guzzle
        // matches the URL host against the certificate SAN exactly as Node and
        // Go do, so a pass here is a pass for the client.
        $ca = is_file(self::LETSENCRYPT_CERT) ? true : self::SELF_SIGNED_CERT;
        if ($ca !== true && !is_file($ca)) {
            $ca = true;
        }

        $request = fn() => Http::withOptions(['verify' => $ca])->timeout(15)->connectTimeout(10);

        try {
            $response = $request()->withToken($token)->get("{$base}/mcp/check");
        } catch (ConnectionException $e) {
            $this->fail_("Could not reach {$base}: " . $this->firstLine($e->getMessage()));
            $this->hint($ca);
            return 1;
        }

        $this->pass("TLS certificate accepted for {$base}");

        // /mcp/check runs the same auth:sanctum -> mcp.auth chain as /mcp
        // itself, so a pass here is a pass for the MCP endpoint.
        if ($response->successful() && $response->json('valid') === true) {
            $this->pass('MCP token accepted (GET /mcp/check)');
        } elseif ($response->status() === 401) {
            $this->fail_("MCP token rejected with 401 - it is missing the 'mcp' ability or was revoked");
        } else {
            $this->fail_("GET /mcp/check returned HTTP {$response->status()}");
        }

        // Auth has to be enforced, not merely available: a 200 here would mean
        // the endpoint is open to anyone who can reach the port.
        try {
            $anonymous = $request()->get("{$base}/mcp/check")->status();
            if ($anonymous === 401) {
                $this->pass('Unauthenticated requests rejected (401)');
            } else {
                $this->fail_("Unauthenticated request returned HTTP {$anonymous}, expected 401");
            }
        } catch (ConnectionException $e) {
            $this->fail_('Anonymous check failed: ' . $this->firstLine($e->getMessage()));
        }

        // An authenticated endpoint that cannot speak JSON-RPC still leaves the
        // client with no tools.
        try {
            $handshake = $request()
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->post("{$base}/mcp", [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-06-18',
                        'capabilities' => (object) [],
                        'clientInfo' => ['name' => 'mcp:check', 'version' => '1'],
                    ],
                ]);

            $name = $this->serverName($handshake->body());
            if ($name !== null) {
                $this->pass("MCP handshake completed with '{$name}'");
            } else {
                $this->fail_(sprintf(
                    'MCP handshake failed (HTTP %d): %s',
                    $handshake->status(),
                    $this->firstLine(substr($handshake->body(), 0, 200)) ?: '(empty body)'
                ));
            }
        } catch (ConnectionException $e) {
            $this->fail_('MCP handshake failed: ' . $this->firstLine($e->getMessage()));
        }

        if (!$this->ok) {
            $this->hint($ca);
            return 1;
        }

        return 0;
    }

    private function token(): ?string
    {
        $argument = $this->argument('token');
        if (is_string($argument) && $argument !== '') {
            $this->getOutput()->getErrorStyle()->writeln(
                '<comment>Passing the token as an argument is deprecated: it shows in ps and in your '
                . 'shell history. Run `pae mcp:check` without it to be prompted.</comment>'
            );
            return $argument;
        }

        $stdin = $this->stdin();
        if ($this->option('stdin') || !stream_isatty($stdin)) {
            $line = fgets($stdin);
            $token = is_string($line) ? trim($line) : '';
            return $token === '' ? null : $token;
        }

        $token = $this->secret('MCP token');
        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /** @return resource */
    protected function stdin()
    {
        return STDIN;
    }

    /**
     * The transport answers `initialize` with plain JSON, but a streamed reply
     * would arrive as an SSE frame; accept either rather than call a working
     * server broken over its framing.
     */
    private function serverName(string $body): ?string
    {
        $body = preg_replace('/^data: /m', '', $body) ?? $body;
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $name = data_get($decoded, 'result.serverInfo.name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function hint(bool|string $ca): void
    {
        if ($ca === true) {
            return;
        }
        $this->newLine();
        $this->warn("The engine is using a self-signed certificate ({$ca}).");
        $this->warn('Copy it to the client machine and trust it there. The Node-based clients');
        $this->warn('(Claude Code, Gemini CLI, VS Code, Cursor) read it from');
        $this->warn('NODE_EXTRA_CA_CERTS=/path/to/server.cert, exported in every shell that starts them.');
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }

    private function pass(string $message): void
    {
        $this->line("  <fg=green>[ OK ]</> {$message}");
    }

    private function fail_(string $message): void
    {
        $this->line("  <fg=yellow>[FAIL]</> {$message}");
        $this->ok = false;
    }
}
