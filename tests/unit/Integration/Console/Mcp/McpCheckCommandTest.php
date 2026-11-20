<?php

namespace Tests\Integration\Console\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `mcp:check` reports each of its four checks against what the endpoint answers. */
class McpCheckCommandTest extends TestCase
{
    private const BASE = 'https://engine.example.test:2011';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::BASE.'/']);
        Http::preventStrayRequests();
    }

    /** Answers GET /mcp/check with and without the token, and POST /mcp, with the three callbacks. */
    private function endpoint(callable $authenticated, callable $anonymous, callable $handshake): void
    {
        Http::fake(function (Request $request) use ($authenticated, $anonymous, $handshake) {
            if ($request->method() === 'POST') {
                return $handshake($request);
            }

            return $request->hasHeader('Authorization') ? $authenticated($request) : $anonymous($request);
        });
    }

    public function test_a_healthy_endpoint_passes_every_check(): void
    {
        $this->endpoint(
            fn () => Http::response(['valid' => true]),
            fn () => Http::response([], 401),
            fn () => Http::response("data: ".json_encode(['result' => ['serverInfo' => ['name' => 'PanelAlpha Engine']]])),
        );

        $this->artisan('mcp:check', ['token' => '1|good'])
            ->expectsOutputToContain('[ OK ] TLS certificate accepted for '.self::BASE)
            ->expectsOutputToContain('[ OK ] MCP token accepted (GET /mcp/check)')
            ->expectsOutputToContain('[ OK ] Unauthenticated requests rejected (401)')
            ->expectsOutputToContain("[ OK ] MCP handshake completed with 'PanelAlpha Engine'")
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/mcp'
            && $request['method'] === 'initialize'
            && $request->hasHeader('Authorization', 'Bearer 1|good'));
    }

    public function test_a_rejected_token_an_open_endpoint_and_a_broken_handshake_each_fail(): void
    {
        $this->endpoint(
            fn () => Http::response(['message' => 'Unauthenticated.'], 401),
            fn () => Http::response(['valid' => true], 200),
            fn () => Http::response("<html>\nBad gateway</html>", 502),
        );

        $this->artisan('mcp:check', ['token' => '1|revoked'])
            ->expectsOutputToContain("[FAIL] MCP token rejected with 401 - it is missing the 'mcp' ability or was revoked")
            ->expectsOutputToContain('[FAIL] Unauthenticated request returned HTTP 200, expected 401')
            ->expectsOutputToContain('[FAIL] MCP handshake failed (HTTP 502): <html>')
            ->assertExitCode(1);
    }

    public function test_an_unexpected_status_and_an_empty_handshake_fail(): void
    {
        $this->endpoint(
            fn () => Http::response('', 500),
            fn () => Http::response([], 401),
            fn () => Http::response('', 500),
        );

        $this->artisan('mcp:check', ['token' => '1|token'])
            ->expectsOutputToContain('[FAIL] GET /mcp/check returned HTTP 500')
            ->expectsOutputToContain('[FAIL] MCP handshake failed (HTTP 500): (empty body)')
            ->assertExitCode(1);
    }

    public function test_an_unreachable_endpoint_stops_after_the_first_request(): void
    {
        Http::fake(fn () => throw new ConnectionException("cURL error 7: Failed to connect\nmore detail"));

        $this->artisan('mcp:check', ['token' => '1|token'])
            ->expectsOutputToContain('[FAIL] Could not reach '.self::BASE.': cURL error 7: Failed to connect')
            ->doesntExpectOutputToContain('more detail')
            ->assertExitCode(1);
    }

    public function test_connections_dropped_after_the_token_check_are_reported(): void
    {
        $this->endpoint(
            fn () => Http::response(['valid' => true]),
            fn () => throw new ConnectionException('Connection reset by peer'),
            fn () => throw new ConnectionException('Operation timed out'),
        );

        $this->artisan('mcp:check', ['token' => '1|token'])
            ->expectsOutputToContain('[FAIL] Anonymous check failed: Connection reset by peer')
            ->expectsOutputToContain('[FAIL] MCP handshake failed: Operation timed out')
            ->assertExitCode(1);
    }

    public function test_without_an_app_url_there_is_nothing_to_check(): void
    {
        config(['app.url' => '']);
        Http::fake();

        $this->artisan('mcp:check', ['token' => '1|token'])
            ->expectsOutput('APP_URL is not set, so there is no endpoint to check.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
