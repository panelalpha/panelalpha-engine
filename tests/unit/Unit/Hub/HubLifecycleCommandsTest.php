<?php

namespace Tests\Unit\Hub;

use App\Lib\Hub\HubSigner;
use App\Lib\Hub\SpkiPin;
use App\Models\HubConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** hub:status, hub:disconnect and hub:tls-announce. */
class HubLifecycleCommandsTest extends HubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /** A14 */
    public function test_disconnect_revokes_locally_even_when_the_hub_is_unreachable(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->artisan('hub:disconnect')
            ->expectsOutputToContain('Could not tell the Hub')
            ->expectsOutputToContain('Disconnected from Acme Ltd.')
            ->assertExitCode(0);

        $this->assertNotNull($token->accessToken->fresh()->revoked_at);
        $this->assertNull($connection->fresh());
    }

    public function test_disconnect_sends_a_signed_notice(): void
    {
        $this->connection($this->hubToken());
        Http::fake([self::HUB . '/api/engine/v1/connection/disconnect' => Http::response(null, 204)]);

        $this->artisan('hub:disconnect')->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            $expected = HubSigner::sign('POST', 'api/engine/v1/connection/disconnect', $request->header('X-Timestamp')[0], $request->body(), self::SECRET);

            return $request->header('X-Server-Id')[0] === 'hc_TEST'
                && $request->header('X-Signature')[0] === $expected
                && $request->data() === ['reason' => 'operator'];
        });
    }

    public function test_a_replayed_notice_is_signed_again_and_resent_once(): void
    {
        $this->connection($this->hubToken());
        Http::fake([self::HUB . '/*' => Http::sequence()->push(null, 409)->push(null, 204)]);

        $this->artisan('hub:disconnect')->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_status_shows_the_hubs_view(): void
    {
        $this->connection($this->hubToken(), ['admin_sso']);
        Http::fake([self::HUB . '/api/engine/v1/connection' => Http::response([
            'status' => 'connected', 'account' => ['id' => 42], 'license' => ['id' => 101], 'grants' => ['admin_sso'],
        ])]);

        $this->artisan('hub:status')
            ->expectsOutputToContain('Connected to PanelAlpha Connect')
            ->expectsOutputToContain("The Hub's view")
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertExitCode(0);
    }

    public function test_status_cleans_up_when_the_hub_no_longer_knows_the_connection(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        Http::fake([self::HUB . '/api/engine/v1/connection' => Http::response(null, 403)]);

        $this->artisan('hub:status')
            ->expectsOutputToContain('The Hub no longer knows this connection')
            ->assertExitCode(0);

        $this->assertNotNull($token->accessToken->fresh()->revoked_at);
        $this->assertNull($connection->fresh());
    }

    public function test_status_when_not_connected(): void
    {
        Http::fake();
        $this->artisan('hub:status')->expectsOutputToContain('Not connected')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_tls_announce_sends_a_changed_pin_and_stores_it(): void
    {
        $connection = $this->connection($this->hubToken());
        Http::fake([self::HUB . '/api/engine/v1/connection/tls-pin' => Http::response(null, 204)]);
        $pin = SpkiPin::fromFile($this->certificate);

        $this->artisan('hub:tls-announce', ['certificate' => $this->certificate])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => $r->data() === ['next_tls_spki' => $pin]);
        $this->assertSame($pin, $connection->fresh()->tls_spki);

        // Same key again: nothing to say.
        $this->artisan('hub:tls-announce', ['certificate' => $this->certificate])->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_tls_announce_never_fails_a_renewal(): void
    {
        $this->connection($this->hubToken());
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->artisan('hub:tls-announce', ['certificate' => $this->certificate])->assertExitCode(0);
        $this->artisan('hub:tls-announce', ['certificate' => '/nonexistent'])->assertExitCode(0);
    }

    public function test_tls_announce_without_a_connection_does_nothing(): void
    {
        Http::fake();
        $this->artisan('hub:tls-announce')->assertExitCode(0);
        Http::assertNothingSent();
    }



}
