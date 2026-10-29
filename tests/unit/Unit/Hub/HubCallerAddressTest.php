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
use Tests\Support\TestNetwork;

/**
 * The address PanelAlpha Connect calls from is kept out of the engine-api
 * jail while a connection uses it, and only out of that jail.
 */
class HubCallerAddressTest extends HubTestCase
{
    private const API_LIST = '/etc/fail2ban/panelalpha-api-ignoreip';
    private const TRUSTED_LIST = '/etc/fail2ban/panelalpha-ignoreip';

    private static function connect(): string
    {
        return TestNetwork::public3(31);
    }

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

        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();

        $this->assertSame([self::connect()], array_keys($connection->fresh()->caller_ips));
        $this->assertSame(self::connect()." # PanelAlpha Connect\n", $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(1, $this->fail2ban->reloads, 'written once, not on every call');
        $this->assertArrayNotHasKey(self::TRUSTED_LIST, $this->fail2ban->files, 'SSH, SFTP and FTP still ban it');
    }

    public function test_a_refused_call_exempts_nothing(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);

        $this->callFrom(self::connect(), $token->plainTextToken, '/api/projects')->assertForbidden();
        $this->callFrom(self::connect(), 'not-a-token')->assertUnauthorized();

        $this->assertNull($connection->fresh()->caller_ips);
        $this->assertSame(0, $this->fail2ban->reloads);
    }

    public function test_another_token_exempts_nothing(): void
    {
        $this->connection($this->hubToken());

        $this->callFrom(self::connect(), $this->adminToken())->assertOk();

        $this->assertSame(0, $this->fail2ban->reloads);
    }

    public function test_calls_alternating_between_two_addresses_exempt_both_and_reload_once_per_new_address(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);

        foreach ([TestNetwork::public2(20), self::connect(), TestNetwork::public2(20), self::connect(), TestNetwork::public2(20)] as $ip) {
            $this->callFrom($ip, $token->plainTextToken)->assertOk();
        }

        $this->assertSame([TestNetwork::public2(20), self::connect()], array_keys($connection->fresh()->caller_ips));
        $this->assertSame(
            self::apiList(TestNetwork::public2(20), self::connect()),
            $this->fail2ban->files[self::API_LIST]
        );
        $this->assertSame(2, $this->fail2ban->reloads);
    }

    public function test_the_addresses_are_bounded_and_the_least_recently_seen_goes_first(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        $addresses = array_map(fn (int $n): string => TestNetwork::public2($n), range(1, HubCallerAddress::MAX_ADDRESSES + 1));

        foreach ($addresses as $ip) {
            $this->travel(1)->minutes();
            $this->callFrom($ip, $token->plainTextToken)->assertOk();
        }

        $kept = array_keys($connection->fresh()->caller_ips);
        $this->assertCount(HubCallerAddress::MAX_ADDRESSES, $kept);
        $this->assertNotContains(TestNetwork::public2(1), $kept);
        $this->assertStringNotContainsString(TestNetwork::public2(1).' ', $this->fail2ban->files[self::API_LIST]);
        $this->assertStringContainsString(TestNetwork::public2(9).' # PanelAlpha Connect', $this->fail2ban->files[self::API_LIST]);
    }

    public function test_an_address_connect_stopped_calling_from_is_dropped(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        $this->callFrom(TestNetwork::public2(20), $token->plainTextToken)->assertOk();

        $this->travel(HubCallerAddress::FORGET_AFTER_DAYS * 24 - 1)->hours();
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();
        $this->assertSame([self::connect(), TestNetwork::public2(20)], array_keys($connection->fresh()->caller_ips));

        $this->travel(2)->hours();
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();

        $this->assertSame([self::connect()], array_keys($connection->fresh()->caller_ips));
        $this->assertSame(self::connect()." # PanelAlpha Connect\n", $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(3, $this->fail2ban->reloads);
    }

    public function test_a_known_address_is_refreshed_hourly_without_touching_the_jail(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();
        $first = $connection->fresh()->caller_ips[self::connect()];

        $this->travel(30)->minutes();
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();
        $this->assertSame($first, $connection->fresh()->caller_ips[self::connect()], 'not rewritten on every call');

        $this->travel(HubCallerAddress::REFRESH_SECONDS)->seconds();
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();

        $this->assertGreaterThan($first, $connection->fresh()->caller_ips[self::connect()]);
        $this->assertSame(1, $this->fail2ban->reloads);
    }

    public function test_revoking_the_token_drops_the_address(): void
    {
        $token = $this->hubToken();
        $this->connection($token);
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();

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
        $this->callFrom(self::connect(), $old->plainTextToken)->assertOk();
        $this->callFrom(self::connect(), $new->plainTextToken)->assertOk();

        $this->withToken($old->plainTextToken)->deleteJson('/api/tokens/current')->assertNoContent();

        $this->assertNull(HubConnection::query()->where('connection_id', 'hc_OLD')->first());
        $this->assertSame(self::connect()." # PanelAlpha Connect\n", $this->fail2ban->files[self::API_LIST]);
        $this->assertSame(1, $this->fail2ban->reloads, 'the list did not change, so fail2ban was not reloaded');
    }

    public function test_disconnecting_drops_the_address(): void
    {
        Sleep::fake();
        $token = $this->hubToken();
        $this->connection($token);
        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();
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

        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'fail2ban did not start'))->once();
    }

    public function test_a_firewall_that_cannot_exempt_an_address_is_a_warning(): void
    {
        Log::spy();
        config(['env.FIREWALL_PROVIDER' => 'other']);
        FirewallFactory::register('other', fn () => Mockery::mock(Firewall::class, ['name' => 'other']));
        $token = $this->hubToken();
        $connection = $this->connection($token);

        $this->callFrom(self::connect(), $token->plainTextToken)->assertOk();

        $this->assertSame([self::connect()], array_keys($connection->fresh()->caller_ips));
        $this->assertSame(0, $this->fail2ban->reloads);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'the other firewall cannot exempt it'))->once();
    }

    public function test_sync_exempts_each_connected_address_once(): void
    {
        $seen = now()->toIso8601ZuluString();
        $this->connection($this->hubToken([], 'hc_A'), [], 'hc_A')->forceFill(['caller_ips' => [self::connect() => $seen]])->save();
        $this->connection($this->hubToken([], 'hc_B'), [], 'hc_B')->forceFill(['caller_ips' => [self::connect() => $seen]])->save();
        $this->connection($this->hubToken([], 'hc_C'), [], 'hc_C')->forceFill(['caller_ips' => ['2001:db8::7' => $seen]])->save();
        $this->connection($this->hubToken([], 'hc_D'), [], 'hc_D');

        HubCallerAddress::sync();
        HubCallerAddress::sync();

        $this->assertSame(
            self::apiList(self::connect(), '2001:db8::7'),
            $this->fail2ban->files[self::API_LIST]
        );
        $this->assertSame(1, $this->fail2ban->reloads);
    }

    private function callFrom(string $ip, string $token, string $uri = '/api/projects/all'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withToken($token)->getJson($uri);
    }

    /** The API jail's list as the engine writes it: one line per address, in string order. */
    private static function apiList(string ...$addresses): string
    {
        sort($addresses, SORT_STRING);

        return implode('', array_map(static fn (string $ip): string => $ip." # PanelAlpha Connect\n", $addresses));
    }
}
