<?php

namespace Tests\Integration\Integrations\Tunnels;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * What PanelAlpha Connect is never asked, because the engine refuses it first, and how an
 * answer that is not a success reads; plus the tunnel row a registered name becomes.
 */
class PanelAlphaConnectRefusalsTest extends TestCase
{
    use InMemoryDatabase;

    private const SITES = 'connect.panelalpha.test/api/without-dns/sites*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['license_key' => '', 'default_ipv4' => TestNetwork::public(10)]);
        config(['connect.url' => 'https://connect.panelalpha.test/']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function badLabels(): array
    {
        return [
            'leading dash' => ['-shop.panelalpha.online'],
            'underscore' => ['my_shop'],
            'empty label' => ['.panelalpha.online'],
        ];
    }

    #[DataProvider('badLabels')]
    public function test_a_name_that_is_not_one_clean_label_is_refused(string $hostname): void
    {
        Http::fake();

        try {
            PanelAlphaConnect::assertPanelAlphaOnlineHostname($hostname);
            $this->fail("'{$hostname}' must be refused");
        } catch (PanelAlphaException $e) {
            $this->assertSame("Hostname must be a single label under *.panelalpha.online (got '{$hostname}').", $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{\Closure(PanelAlphaConnect): mixed, string}> */
    public static function badTargets(): array
    {
        return [
            'create without a domain' => [
                fn (PanelAlphaConnect $c) => $c->createSite('  ', TestNetwork::public(10), 'shop'),
                'target_domain is required.',
            ],
            'create on a name instead of an address' => [
                fn (PanelAlphaConnect $c) => $c->createSite('shop.example.test', 'engine.example.test', 'shop'),
                "A valid public IPv4 (default_ipv4) is required to create a PanelAlpha Online tunnel (got 'engine.example.test').",
            ],
            'update without a domain' => [
                fn (PanelAlphaConnect $c) => $c->updateSite('shop.panelalpha.online', '', TestNetwork::public(10)),
                'target_domain is required.',
            ],
            'update without an address' => [
                fn (PanelAlphaConnect $c) => $c->updateSite('shop', 'shop.example.test', ' '),
                "A valid public IPv4 (default_ipv4) is required to update a PanelAlpha Online tunnel (got '').",
            ],
        ];
    }

    /** @param \Closure(PanelAlphaConnect): mixed $call */
    #[DataProvider('badTargets')]
    public function test_a_target_the_proxy_could_not_forward_to_is_refused_before_it_is_asked(\Closure $call, string $message): void
    {
        Http::fake();

        try {
            $call(new PanelAlphaConnect);
            $this->fail('the target must be refused');
        } catch (PanelAlphaException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_tunnel_needs_the_engines_public_ipv4(): void
    {
        Setting::setRuntimeSettings(['license_key' => '', 'default_ipv4' => 'not-an-ip']);
        Http::fake();
        $user = $this->makeUser('shop');

        $this->expectException(PanelAlphaException::class);
        $this->expectExceptionMessage('default_ipv4 must be set to a valid IPv4 before creating a PanelAlpha Online tunnel.');

        PanelAlphaConnect::createTunnel($user, $this->makeMainDomain($user, 'shop.example.test'), 'shop');
    }

    public function test_a_preview_forwards_its_domain_and_the_row_keeps_the_lifetime(): void
    {
        Http::fake([self::SITES => Http::response([
            'status' => 'success',
            'data' => ['path' => 'preview-1', 'id' => '17', 'valid_until' => '2030-01-01 00:00:00'],
        ])]);
        $user = $this->makeUser('shop');
        $domain = $this->makeMainDomain($user, 'shop.example.test');

        $tunnel = PanelAlphaConnect::createTunnel($user, $domain, 'preview-1.panelalpha.online', forwardDomain: true);

        Http::assertSent(fn ($request): bool => $request['target_domain'] === 'shop.example.test' && $request['path'] === 'preview-1');
        $this->assertSame('preview-1.panelalpha.online', $tunnel->hostname);
        $this->assertSame([
            'path' => 'preview-1',
            'target_ip' => TestNetwork::public(10),
            'target_domain' => 'shop.example.test',
            'wdns_site_id' => 17,
            'valid_until' => '2030-01-01 00:00:00',
        ], Tunnel::query()->findOrFail($tunnel->id)->getDetails());
    }

    /** @return array<string, array{mixed, int, string}> */
    public static function failedAnswers(): array
    {
        return [
            'not json' => ['<html>bad gateway</html>', 502, 'PanelAlpha Online create failed: invalid JSON response (HTTP 502).'],
            'error field' => [['status' => 'error', 'error' => 'quota exceeded'], 429, 'PanelAlpha Online create failed: quota exceeded (HTTP 429).'],
            'nothing said' => [['status' => 'pending'], 200, 'PanelAlpha Online create failed: create failed (HTTP 200).'],
        ];
    }

    #[DataProvider('failedAnswers')]
    public function test_an_answer_that_is_not_a_success_names_what_connect_said(mixed $body, int $status, string $message): void
    {
        Http::fake([self::SITES => Http::response($body, $status)]);

        try {
            (new PanelAlphaConnect)->createSite('shop.example.test', TestNetwork::public(10), 'shop');
            $this->fail('a failed create must raise');
        } catch (PanelAlphaException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->responseStatus());
        }
    }

    public function test_a_delete_refused_without_a_message_says_so_with_its_status(): void
    {
        Http::fake([self::SITES => Http::response(['status' => 'error'], 403)]);

        try {
            (new PanelAlphaConnect)->deleteSite('shop');
            $this->fail('a refused delete must raise');
        } catch (PanelAlphaException $e) {
            $this->assertSame('PanelAlpha Online delete failed: delete failed (HTTP 403).', $e->getMessage());
            $this->assertSame(403, $e->responseStatus());
        }

        Http::assertSent(fn ($request): bool => $request->url() === 'https://connect.panelalpha.test/api/without-dns/sites/shop.panelalpha.online');
    }

    public function test_a_returned_bare_label_still_names_the_full_hostname(): void
    {
        Http::fake([self::SITES => Http::response(['status' => 'success', 'data' => ['path' => 'shop', 'model' => ['id' => 'x']]])]);

        $created = (new PanelAlphaConnect)->updateSite('shop.panelalpha.online', 'shop.example.test', TestNetwork::public(10));

        $this->assertSame('shop.panelalpha.online', $created['path_fqdn']);
        $this->assertNull($created['wdns_site_id']);
        $this->assertNull($created['valid_until']);
    }

    public function test_a_domain_with_a_panelalpha_tunnel_trusts_the_front(): void
    {
        config(['connect.front_addresses' => TestNetwork::public(20).', '.TestNetwork::public(21).'/33 nonsense']);
        $user = $this->makeUser('shop');
        $tunnelled = $this->makeMainDomain($user, 'shop.example.test');
        $plain = $this->makeMainDomain($user, 'plain.example.test');
        Tunnel::create([
            'domain_id' => $tunnelled->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop.panelalpha.online',
            'details' => [],
        ]);

        $this->assertSame([TestNetwork::public(20)], PanelAlphaConnect::trustedFrontsFor($tunnelled));
        $this->assertSame([], PanelAlphaConnect::trustedFrontsFor($plain));
    }
}
