<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ClientRegistration;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `pae mcp:token:create --client=<unknown>` refuses the client before minting,
 * so a typo leaves no working token behind.
 */
class McpTokenCreateCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMockingConsoleOutput();
    }

    public function test_an_unknown_client_is_refused_without_registering(): void
    {
        [$code, $output] = $this->create('nosuch', ['--no-register' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString($this->unknownClientMessage('nosuch'), $output);
        $this->assertStringNotContainsString('MCP token created', $output);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_unknown_client_is_refused_before_the_token_is_printed(): void
    {
        [$code, $output] = $this->create('nosuch');

        $this->assertSame(1, $code);
        $this->assertStringContainsString($this->unknownClientMessage('nosuch'), $output);
        $this->assertStringNotContainsString('MCP token created', $output);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_known_client_still_mints_a_token(): void
    {
        [$code] = $this->create('claude', ['--no-register' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(1, PersonalAccessToken::count());
    }

    /** The client the operator typed is the one the printed command is for. */
    public function test_a_known_client_gets_its_registration_command(): void
    {
        [$code, $output] = $this->create('Claude');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Claude Code', $output);
        $this->assertStringNotContainsString('Unknown client', $output);
        $this->assertSame(1, PersonalAccessToken::count());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function create(string $client, array $options = []): array
    {
        $code = Artisan::call('mcp:token:create', ['name' => 'laptop', '--client' => $client] + $options);

        return [$code, Artisan::output()];
    }

    private function unknownClientMessage(string $client): string
    {
        return sprintf('Unknown client "%s". Known clients: %s.', $client, implode(', ', ClientRegistration::clients()));
    }
}
