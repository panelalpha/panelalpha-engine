<?php

namespace Tests\Integration\Console\Api;

use App\Auth\TokenAbilities;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** api:token:create and :delete, and mcp:token:list, :revoke and :delete, on the root admin's tokens. */
class TokenCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Carbon::setTestNow('2026-03-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(string $name, bool $mcp, ?array $commands = null): PersonalAccessToken
    {
        return Admin::rootAccount()->createToken($name, TokenAbilities::build(api: ! $mcp, mcp: $mcp, commands: $commands))->accessToken;
    }

    public function test_create_prints_the_token_and_its_lifetime(): void
    {
        $this->artisan('api:token:create billing --expires=2w')
            ->expectsOutput('Api token created (expires 2026-03-15 12:00).')
            ->expectsOutput("Save it, it won't be retrieveable again.")
            ->assertExitCode(0);

        $token = Admin::rootAccount()->tokens()->sole();
        $this->assertSame('billing', $token->name);
        $this->assertSame('2026-03-15 12:00:00', $token->expires_at->format('Y-m-d H:i:s'));
        $this->assertTrue(TokenAbilities::of($token)->mayUseApi());
        $this->assertFalse(TokenAbilities::of($token)->mayUseMcp());
    }

    public function test_create_short_prints_only_a_never_expiring_token_that_may_speak_mcp(): void
    {
        $this->artisan('api:token:create scripts --short --mcp')
            ->expectsOutputToContain('|')
            ->doesntExpectOutputToContain('Api token created')
            ->assertExitCode(0);

        $token = Admin::rootAccount()->tokens()->sole();
        $this->assertNull($token->expires_at);
        $this->assertTrue(TokenAbilities::of($token)->mayUseMcp());
    }

    public function test_create_reads_every_unit_and_refuses_an_unreadable_expiry(): void
    {
        foreach (['30s' => '2026-03-01 12:00:30', '5m' => '2026-03-01 12:05:00', '12h' => '2026-03-02 00:00:00', '3d' => '2026-03-04 12:00:00'] as $given => $expected) {
            $this->artisan("api:token:create t{$given} --expires={$given}")
                ->expectsOutput('Api token created (expires '.substr($expected, 0, 16).').')
                ->assertExitCode(0);
        }

        $this->artisan('api:token:create bad --expires=soon')
            ->expectsOutput('Could not read --expires=soon. Give a number and a unit: 30m, 12h, 90d, 2w.')
            ->assertExitCode(1);
        $this->artisan('api:token:create zero --expires=0d')
            ->expectsOutput('--expires has to be at least 1.')
            ->assertExitCode(1);
        $this->artisan('api:token:create never')
            ->expectsOutput('Api token created (never expires).')
            ->assertExitCode(0);

        $this->assertSame(5, Admin::rootAccount()->tokens()->count());
    }

    public function test_delete_removes_a_token_or_reports_an_unknown_id(): void
    {
        $token = $this->token('old', false);

        $this->artisan('api:token:delete 999')->expectsOutput('Invalid ID: 999')->assertExitCode(1);
        $this->artisan("api:token:delete {$token->id}")
            ->expectsOutput("Api token #{$token->id} 'old' deleted.")
            ->assertExitCode(0);

        $this->assertSame(0, Admin::rootAccount()->tokens()->count());
    }

    public function test_mcp_list_shows_only_tokens_that_may_speak_mcp(): void
    {
        $this->token('rest-only', false);
        $this->token('desk-zed', true);
        $this->token('desk-yak', true, ['project_list']);

        $this->artisan('mcp:token:list')
            ->expectsOutputToContain('desk-zed')
            ->expectsOutputToContain('desk-yak')
            ->doesntExpectOutputToContain('rest-only')
            ->assertExitCode(0);
    }

    public function test_mcp_revoke_blocks_a_token_once(): void
    {
        $token = $this->token('assistant', true);

        $this->artisan('mcp:token:revoke 999')->expectsOutput('Token #999 not found.')->assertExitCode(1);
        $this->artisan("mcp:token:revoke {$token->id}")
            ->expectsOutput("Token #{$token->id} 'assistant' revoked.")
            ->assertExitCode(0);
        $this->artisan("mcp:token:revoke {$token->id}")
            ->expectsOutput("Token #{$token->id} 'assistant' is already revoked.")
            ->assertExitCode(0);

        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_mcp_delete_removes_a_token_or_reports_an_unknown_id(): void
    {
        $token = $this->token('assistant', true);

        $this->artisan('mcp:token:delete 999')->expectsOutput('Token #999 not found.')->assertExitCode(1);
        $this->artisan("mcp:token:delete {$token->id}")
            ->expectsOutput("Token #{$token->id} 'assistant' deleted.")
            ->assertExitCode(0);

        $this->assertNull($token->fresh());
    }
}
