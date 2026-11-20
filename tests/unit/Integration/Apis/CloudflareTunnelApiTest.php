<?php

namespace Tests\Integration\Apis;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The tunnel half of the Cloudflare client against a faked API: find, create, token, ingress, delete. */
class CloudflareTunnelApiTest extends TestCase
{
    private const BASE = 'api.cloudflare.com/client/v4/accounts/acc-1/cfd_tunnel';

    public function test_a_tunnel_is_found_by_exact_name_and_its_token_fetched(): void
    {
        Http::fake([
            self::BASE.'/t-2/token' => Http::response(['success' => true, 'result' => " tok-2 \n"]),
            self::BASE.'*' => Http::response(['success' => true, 'result' => [
                'not a tunnel',
                ['id' => 't-1', 'name' => 'engine-other'],
                ['id' => '', 'name' => 'engine-a'],
                ['id' => 't-2', 'name' => 'engine-a'],
            ]]),
        ]);

        $tunnel = (new Cloudflare('token'))->findTunnelByName('acc-1', 'engine-a');

        $this->assertSame(['id' => 't-2', 'name' => 'engine-a', 'token' => 'tok-2'], $tunnel);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'name=engine-a')
            && str_contains($r->url(), 'is_deleted=false')
            && $r->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_no_matching_tunnel_is_null(): void
    {
        Http::fake([self::BASE.'*' => Http::response(['success' => true, 'result' => [['id' => 't-1', 'name' => 'engine-b']]])]);
        $this->assertNull((new Cloudflare('token'))->findTunnelByName('acc-1', 'engine-a'));

        Http::fake([self::BASE.'*' => Http::response(['success' => true, 'result' => 'none'])]);
        $this->assertNull((new Cloudflare('token'))->findTunnelByName('acc-1', 'engine-a'));
    }

    public function test_a_created_tunnel_carries_the_token_from_the_create_answer(): void
    {
        Http::fake([self::BASE => Http::response(['success' => true, 'result' => ['id' => 't-9', 'token' => 'tok-9']])]);

        $this->assertSame(
            ['id' => 't-9', 'name' => 'engine-a', 'token' => 'tok-9'],
            (new Cloudflare('token'))->createTunnel('acc-1', 'engine-a')
        );
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r['name'] === 'engine-a' && $r['config_src'] === 'cloudflare');
    }

    public function test_a_created_tunnel_without_a_token_asks_for_one(): void
    {
        Http::fake([
            self::BASE.'/t-9/token' => Http::response(['success' => true, 'result' => 'tok-fetched']),
            self::BASE => Http::response(['success' => true, 'result' => ['id' => 't-9']]),
        ]);

        $this->assertSame('tok-fetched', (new Cloudflare('token'))->createTunnel('acc-1', 'engine-a')['token']);
    }

    /** @return array<string, array{mixed, string}> */
    public static function badCreateAnswers(): array
    {
        return [
            'result not an object' => ['oops', 'Unexpected response creating Cloudflare tunnel.'],
            'no id' => [['token' => 'tok'], 'Cloudflare tunnel id missing from create response.'],
        ];
    }

    #[DataProvider('badCreateAnswers')]
    public function test_a_create_answer_without_a_tunnel_is_an_error(mixed $result, string $message): void
    {
        Http::fake([self::BASE => Http::response(['success' => true, 'result' => $result])]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage($message);

        (new Cloudflare('token'))->createTunnel('acc-1', 'engine-a');
    }

    public function test_an_empty_token_is_an_error(): void
    {
        Http::fake([self::BASE.'/t-1/token' => Http::response(['success' => true, 'result' => '  '])]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage("Could not fetch token for Cloudflare tunnel 't-1'.");

        (new Cloudflare('token'))->getTunnelToken('acc-1', 't-1');
    }

    public function test_ingress_is_read_from_the_tunnel_configuration(): void
    {
        $service = 'http://'.TestNetwork::LOOPBACK.':9000';
        Http::fake([self::BASE.'/t-1/configurations' => Http::response(['success' => true, 'result' => ['config' => ['ingress' => [
            'a' => ['hostname' => 'app.example.com', 'service' => $service],
            'b' => ['service' => 'http_status:404'],
        ]]]])]);

        $this->assertSame(
            [['hostname' => 'app.example.com', 'service' => $service], ['service' => 'http_status:404']],
            (new Cloudflare('token'))->getIngress('acc-1', 't-1')
        );
    }

    /** @return array<string, array{mixed}> */
    public static function emptyConfigurations(): array
    {
        return [
            'result not an object' => ['nope'],
            'config not an object' => [['config' => 'nope']],
            'ingress not a list' => [['config' => ['ingress' => 'nope']]],
        ];
    }

    #[DataProvider('emptyConfigurations')]
    public function test_an_unreadable_configuration_is_no_ingress(mixed $result): void
    {
        Http::fake([self::BASE.'/t-1/configurations' => Http::response(['success' => true, 'result' => $result])]);

        $this->assertSame([], (new Cloudflare('token'))->getIngress('acc-1', 't-1'));
    }

    public function test_ingress_is_put_under_config(): void
    {
        Http::fake([self::BASE.'/t-1/configurations' => Http::response(['success' => true, 'result' => []])]);
        $ingress = [['service' => 'http_status:404']];

        (new Cloudflare('token'))->putIngress('acc-1', 't-1', $ingress);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r['config'] === ['ingress' => $ingress]);
    }

    public function test_deleting_a_tunnel_that_is_already_gone_is_fine(): void
    {
        Http::fake([self::BASE.'/t-1' => Http::response(['success' => false, 'errors' => [['code' => 1003, 'message' => 'Tunnel not found']]], 404)]);

        (new Cloudflare('token'))->deleteTunnel('acc-1', 't-1');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_any_other_delete_failure_is_raised(): void
    {
        Http::fake([self::BASE.'/t-1' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('Authentication error');

        (new Cloudflare('token'))->deleteTunnel('acc-1', 't-1');
    }

    public function test_a_setting_answer_without_a_value_is_an_error(): void
    {
        Http::fake(['api.cloudflare.com/client/v4/zones/z-1/settings/security_level' => Http::response(['success' => true, 'result' => ['id' => 'security_level']])]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage("Cloudflare did not answer with the 'security_level' setting for zone 'z-1'.");

        (new Cloudflare('token'))->getSetting('z-1', 'security_level');
    }

    public function test_ingress_rules_that_are_not_objects_are_dropped(): void
    {
        $service = 'http://'.TestNetwork::LOOPBACK.':9000';

        $this->assertSame(
            [['hostname' => 'app.example.com', 'service' => $service, 'originRequest' => []], ['service' => 'http_status:404']],
            Cloudflare::upsertHostnameIngress(['junk'], 'App.Example.com ', $service, [])
        );
        $this->assertSame([['service' => 'http_status:404']], Cloudflare::removeHostnameIngress(['junk'], 'app.example.com'));
    }

    public function test_a_zone_listing_that_is_not_a_list_gives_no_account(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => 'nope']),
        ]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('Cloudflare API token has no accessible accounts.');

        (new Cloudflare('token'))->resolveAccount();
    }
}
