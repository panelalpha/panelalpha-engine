<?php

namespace Tests\Unit\Hub;

use App\Lib\Hub\HubCallerAddress;
use App\Models\HubConnection;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Mockery;

/**
 * The address PanelAlpha Connect calls from is kept out of the engine-api
 * jail while a connection uses it, and only out of that jail.
 */
class HubCallerAddressTest extends HubTestCase
{
    private const CONNECT = '2.28.195.31';
    private const API_LIST = '/etc/fail2ban/panelalpha-api-ignoreip';
    private const TRUSTED_LIST = '/etc/fail2ban/panelalpha-ignoreip';

    /** Sanctum keeps the first caller for the whole test; each request here is its own. */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    public function test_a_call_its_token_got_through_exempts_its_address_from_the_api_jail_once(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);

        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();
        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();

        $this->assertSame(self::CONNECT, $connection->fresh()->caller_ip);
        $this->assertSame(self::CONNECT." # PanelAlpha Connect\n", $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(1, $this->fail2ban->reloads, 'written once, not on every call');
        $this->assertArrayNotHasKey(self::TRUSTED_LIST, $this->fail2ban->files, 'SSH, SFTP and FTP still ban it');
    }

    public function test_a_refused_call_exempts_nothing(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);

        $this->callFrom(self::CONNECT, $token->plainTextToken, '/api/projects')->assertForbidden();
        $this->callFrom(self::CONNECT, 'not-a-token')->assertUnauthorized();

        $this->assertNull($connection->fresh()->caller_ip);
        $this->assertSame(0, $this->fail2ban->reloads);
    }

    public function test_another_token_exempts_nothing(): void
    {
        $this->connection($this->hubToken());

        $this->callFrom(self::CONNECT, $this->adminToken())->assertOk();

        $this->assertSame(0, $this->fail2ban->reloads);
    }

    public function test_a_new_address_replaces_the_old_one(): void
    {
        $token = $this->hubToken();
        $this->connection($token);

        $this->callFrom('198.51.100.20', $token->plainTextToken)->assertOk();
        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();

        $this->assertSame(self::CONNECT." # PanelAlpha Connect\n", $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(2, $this->fail2ban->reloads);
    }

    public function test_revoking_the_token_drops_the_address(): void
    {
        $token = $this->hubToken();
        $this->connection($token);
        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();

        $this->withToken($token->plainTextToken)->deleteJson('/api/tokens/current')->assertNoContent();

        $this->assertSame('', $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(2, $this->fail2ban->reloads);
    }

    public function test_an_address_another_connection_still_calls_from_is_kept(): void
    {
        $old = $this->hubToken([], 'hc_OLD');
        $new = $this->hubToken([], 'hc_NEW');
        $this->connection($old, [], 'hc_OLD');
        $this->connection($new, [], 'hc_NEW');
        $this->callFrom(self::CONNECT, $old->plainTextToken)->assertOk();
        $this->callFrom(self::CONNECT, $new->plainTextToken)->assertOk();

        $this->withToken($old->plainTextToken)->deleteJson('/api/tokens/current')->assertNoContent();

        $this->assertNull(HubConnection::query()->where('connection_id', 'hc_OLD')->first());
        $this->assertSame(self::CONNECT." # PanelAlpha Connect\n", $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(1, $this->fail2ban->reloads, 'the list did not change, so fail2ban was not reloaded');
    }

    public function test_disconnecting_drops_the_address(): void
    {
        Sleep::fake();
        $token = $this->hubToken();
        $this->connection($token);
        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->artisan('hub:disconnect')->assertExitCode(0);

        $this->assertSame('', $this->fail2ban->files[self::API_LIST]);
    }

    public function test_a_list_fail2ban_does_not_take_never_fails_the_call(): void
    {
        Log::spy();
        $this->fail2ban->reloadFails = true;
        $token = $this->hubToken();
        $this->connection($token);

        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'fail2ban did not start'))->once();
    }

    public function test_a_firewall_that_cannot_exempt_an_address_is_a_warning(): void
    {
        Log::spy();
        config(['env.FIREWALL_PROVIDER' => 'other']);
        FirewallFactory::register('other', fn () => Mockery::mock(Firewall::class, ['name' => 'other']));
        $token = $this->hubToken();
        $connection = $this->connection($token);

        $this->callFrom(self::CONNECT, $token->plainTextToken)->assertOk();

        $this->assertSame(self::CONNECT, $connection->fresh()->caller_ip);
        $this->assertSame(0, $this->fail2ban->reloads);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'the other firewall cannot exempt it'))->once();
    }

    public function test_sync_exempts_each_connected_address_once(): void
    {
        $this->connection($this->hubToken([], 'hc_A'), [], 'hc_A')->forceFill(['caller_ip' => self::CONNECT])->save();
        $this->connection($this->hubToken([], 'hc_B'), [], 'hc_B')->forceFill(['caller_ip' => self::CONNECT])->save();
        $this->connection($this->hubToken([], 'hc_C'), [], 'hc_C')->forceFill(['caller_ip' => '2001:db8::7'])->save();
        $this->connection($this->hubToken([], 'hc_D'), [], 'hc_D');

        HubCallerAddress::sync();
        HubCallerAddress::sync();

        $this->assertSame(
            self::CONNECT." # PanelAlpha Connect\n2001:db8::7 # PanelAlpha Connect\n",
            $this->fail2ban->files[self::API_LIST]
        );
        $this->assertSame(1, $this->fail2ban->reloads);
    }

    private function callFrom(string $ip, string $token, string $uri = '/api/projects/all'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withToken($token)->getJson($uri);
    }
}
