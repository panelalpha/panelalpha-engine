<?php

namespace Tests\Integration\Console\Hub;

use App\Models\HubAccessLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Integration\Hub\HubTestCase;

/** hub:disconnect without a connection or against a failing Hub, hub:status reading back recent calls, and hub:prune. */
class HubHousekeepingCommandsTest extends HubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_disconnect_without_a_connection_revokes_leftover_tokens(): void
    {
        $token = $this->hubToken();
        Http::fake();

        $this->artisan('hub:disconnect')
            ->expectsOutput('Not connected; revoked 1 leftover Hub token(s).')
            ->assertExitCode(0);

        $this->assertNotNull($token->accessToken->fresh()->revoked_at);
        Http::assertNothingSent();

        $this->artisan('hub:disconnect')
            ->expectsOutput('Not connected to PanelAlpha Connect.')
            ->assertExitCode(0);
    }

    public function test_disconnect_reports_a_hub_that_answers_with_an_error(): void
    {
        $this->connection($this->hubToken());
        Http::fake([self::HUB.'/api/engine/v1/connection/disconnect' => Http::response(['message' => 'Maintenance'], 503)]);

        $this->artisan('hub:disconnect')
            ->expectsOutputToContain('(HTTP 503)')
            ->expectsOutput('Disconnected from Acme Ltd.')
            ->assertExitCode(0);
    }

    public function test_status_says_when_the_hub_cannot_be_asked_and_lists_recent_calls(): void
    {
        $this->connection($this->hubToken());
        HubAccessLog::query()->create(['at' => now(), 'method' => 'POST', 'route' => '/hub/projects/shop/sso', 'app' => 'panel', 'status' => 200, 'source_ip' => self::engineIp(), 'detail' => 'admin login']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('hub:status')
            ->expectsOutputToContain('Could not ask the Hub: ')
            ->expectsOutput('Last Hub calls')
            ->expectsOutputToContain('/hub/projects/shop/sso')
            ->assertExitCode(0);
    }

    public function test_prune_drops_only_rows_past_the_retention(): void
    {
        foreach ([HubAccessLog::KEEP_DAYS + 1, HubAccessLog::KEEP_DAYS + 5, 1] as $daysAgo) {
            HubAccessLog::query()->create(['at' => now()->subDays($daysAgo), 'method' => 'GET', 'route' => '/hub/status', 'status' => 200]);
        }

        $this->artisan('hub:prune')->expectsOutput('Pruned 2 access log row(s), 0 report row(s) and 0 login row(s).')->assertExitCode(0);

        $this->assertSame(1, HubAccessLog::query()->count());
    }
}
