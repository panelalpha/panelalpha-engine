<?php

namespace Tests\Integration\Console\Mcp;

use App\Auth\TokenAbilities;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Models\Admin;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** mcp:tool:list, mcp:token:create and mcp:connect --bare, as an operator reads them. */
class McpCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.url' => 'https://engine.example.test:2011']);
    }

    public function test_tool_list_counts_what_the_configuration_exposes(): void
    {
        $total = count(ToolRegistry::all());

        $this->artisan('mcp:tool:list')
            ->expectsOutputToContain('toolsets')
            ->expectsOutputToContain('project_list_summary')
            ->expectsOutputToContain("{$total} exposed")
            ->assertExitCode(0);
    }

    public function test_tool_list_shows_what_a_denial_withholds_and_narrows_to_one_toolset(): void
    {
        config(['mcp-tools.denied' => 'project_list_summary']);
        $total = count(ToolRegistry::all());
        $exposed = count((new ToolPolicy)->filter(ToolRegistry::all()));

        $this->artisan('mcp:tool:list --excluded')
            ->expectsOutputToContain('project_list_summary')
            ->expectsOutputToContain(sprintf('%d exposed, %d withheld, %d total.', $exposed, $total - $exposed, $total))
            ->assertExitCode(0);

        $this->artisan('mcp:tool:list --toolset=Domains')
            ->doesntExpectOutputToContain('metrics_latest')
            ->assertExitCode(0);
    }

    public function test_token_create_short_prints_only_the_token(): void
    {
        $this->artisan('mcp:token:create desk --short --api')
            ->doesntExpectOutputToContain('MCP token created')
            ->assertExitCode(0);

        $token = Admin::rootAccount()->tokens()->sole();
        $this->assertTrue(TokenAbilities::of($token)->mayUseMcp());
        $this->assertTrue(TokenAbilities::of($token)->mayUseApi());
    }

    public function test_token_create_refuses_an_unknown_client_or_expiry_without_minting(): void
    {
        $this->artisan('mcp:token:create desk --client=notepad')
            ->expectsOutputToContain('Unknown client "notepad". Known clients: ')
            ->assertExitCode(1);
        $this->artisan('mcp:token:create desk --expires=forever')
            ->expectsOutputToContain('Could not read --expires=forever.')
            ->assertExitCode(1);

        $this->assertSame(0, Admin::rootAccount()->tokens()->count());
    }

    public function test_token_create_prints_the_registration_for_every_client(): void
    {
        $this->artisan('mcp:token:create desk')
            ->expectsOutput('Register the MCP server with your client:')
            ->expectsOutputToContain('https://engine.example.test:2011/mcp')
            ->assertExitCode(0);
    }

    public function test_connect_bare_mints_an_mcp_only_token_and_prints_its_command(): void
    {
        $this->artisan('mcp:connect claude --bare')
            ->expectsOutputToContain('https://engine.example.test:2011/mcp')
            ->assertExitCode(0);

        $token = Admin::rootAccount()->tokens()->sole();
        $this->assertSame('claude', $token->name);
        $this->assertTrue(TokenAbilities::of($token)->mayUseMcp());
        $this->assertFalse(TokenAbilities::of($token)->mayUseApi());
    }

    public function test_connect_without_bare_creates_the_token_for_that_client(): void
    {
        $this->artisan('mcp:connect Codex')
            ->expectsOutputToContain('MCP token created (never expires).')
            ->doesntExpectOutput('Register the MCP server with your client:')
            ->assertExitCode(0);

        $this->assertSame('codex', Admin::rootAccount()->tokens()->sole()->name);
    }
}
