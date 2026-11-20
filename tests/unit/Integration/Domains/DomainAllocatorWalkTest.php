<?php

namespace Tests\Integration\Domains;

use App\Lib\Domains\AllocatedDomain;
use App\Lib\Domains\DomainAllocationException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * Pins what allocate() returns on each rung of the ladder, the PanelAlpha Online one included,
 * with the proxy faked and the names held locally in an in-memory database.
 */
class DomainAllocatorWalkTest extends TestCase
{
    use InMemoryDatabase;

    private const PROXY = 'connect.panelalpha.com/api/without-dns/sites';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['connect.url' => 'https://connect.panelalpha.com']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @param  array<string, ?string>  $overrides */
    private function engine(array $overrides = []): void
    {
        Setting::setRuntimeSettings($overrides + [
            'license_key' => 'K',
            'default_wildcard_domain' => null,
            'cert_domain' => null,
            'default_ipv4' => TestNetwork::public(9),
            DomainPlan::SETTING_DEFAULT_TUNNEL => null,
            DomainAllocator::SETTING_ONLINE_LAST_ERROR => '',
        ]);
    }

    private static function sold(string $label): array
    {
        return ['status' => 'success', 'data' => ['path' => $label.'.panelalpha.online', 'model' => ['id' => 7]]];
    }

    private static function refused(string $message): array
    {
        return ['status' => 'error', 'message' => $message];
    }

    /** @return array<string, mixed> */
    private static function shape(AllocatedDomain $allocated): array
    {
        return [
            'source' => $allocated->source,
            'tunnel' => $allocated->tunnelProvider,
            'public' => $allocated->publiclyResolvable,
            'tls' => $allocated->tlsTerminatedAt,
            'reason' => $allocated->fallbackReason,
        ];
    }

    private function holdLocally(string $domain): void
    {
        $this->makeMainDomain($this->makeUser('holder'.substr(md5($domain), 0, 6), [], 'holder.example.test'), $domain);
    }

    public function test_a_fresh_public_engine_buys_a_suffixed_online_label(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::sold('shop-ab12'), 200)]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame([
            'source' => DomainPlan::SOURCE_PANELALPHA_ONLINE,
            'tunnel' => 'panelalpha',
            'public' => true,
            'tls' => 'proxy',
            'reason' => null,
        ], self::shape($allocated));
        $this->assertSame('shop-ab12.panelalpha.online', $allocated->domain);
        $this->assertSame(TestNetwork::public(9), $allocated->allocation['target_ip'] ?? null);
        $this->assertSame(7, $allocated->allocation['wdns_site_id'] ?? null);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => preg_match('/^shop-[0-9a-f]{4}$/', (string) $request['path']) === 1);
    }

    public function test_a_label_taken_at_the_proxy_is_retried_under_a_new_suffix(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::sequence()
            ->push(self::refused('The domain name is not available'), 400)
            ->push(self::sold('shop-cd34'), 200)]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame('shop-cd34.panelalpha.online', $allocated->domain);
        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_ONLINE, $allocated->source);
        Http::assertSentCount(2);
    }

    public function test_every_label_taken_drops_to_the_dashed_address_with_the_reason(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::refused('Site already exists. Use PUT to update.'), 409)]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame([
            'source' => DomainPlan::SOURCE_PANELALPHA_DIRECT,
            'tunnel' => null,
            'public' => true,
            'tls' => 'engine',
            'reason' => 'panelalpha_online: every label tried is already registered',
        ], self::shape($allocated));
        $this->assertSame('shop.'.str_replace('.', '-', TestNetwork::public(9)).'.panelalpha.direct', $allocated->domain);
        Http::assertSentCount(5);
    }

    public function test_a_proxy_that_refuses_service_drops_the_rung_and_is_remembered(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::refused('Sites limit reached for this service'), 200)]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_DIRECT, $allocated->source);
        $this->assertSame(
            'panelalpha_online: PanelAlpha Online create failed: Sites limit reached for this service (HTTP 200).',
            $allocated->fallbackReason
        );
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string, int}> */
    public static function refusalsThatAreNotATakenName(): array
    {
        return [
            'a service outage' => ['Service not available', 503],
            'a licence limit' => ['Feature not available for this license', 403],
            'a taken phrase on a success status' => ['Path is already taken', 200],
            'a validation error about another field' => ['The target ip field is required.', 422],
            'a malformed request' => ['Invalid JSON body', 400],
        ];
    }

    #[DataProvider('refusalsThatAreNotATakenName')]
    public function test_a_refusal_that_is_not_about_the_name_is_recorded_with_its_real_message(string $message, int $status): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::refused($message), $status)]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $real = "PanelAlpha Online create failed: {$message} (HTTP {$status}).";
        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_DIRECT, $allocated->source);
        $this->assertSame('panelalpha_online: '.$real, $allocated->fallbackReason);
        $this->assertSame($real, DomainAllocator::lastOnlineError()['message'] ?? null);
        Http::assertSentCount(1);
    }

    public function test_a_requested_online_name_the_proxy_refuses_for_another_reason_is_not_called_taken(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::refused('Service not available'), 503)]);

        try {
            (new DomainAllocator)->allocate('mine', 'mine.panelalpha.online');
            $this->fail('Expected the requested name to be refused.');
        } catch (DomainAllocationException $e) {
            $this->assertSame('PanelAlpha Online create failed: Service not available (HTTP 503).', $e->getMessage());
            $this->assertSame('allocation_failed', $e->reason);
        }
    }

    public function test_a_connect_that_cannot_be_reached_drops_the_rung_and_is_remembered(): void
    {
        $this->engine();
        Http::fake(['*' => Http::failedConnection()]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_DIRECT, $allocated->source);
        $this->assertStringStartsWith(
            'panelalpha_online: PanelAlpha Online create failed: PanelAlpha Connect could not be reached (cURL error 6: Could not resolve host: connect.panelalpha.com',
            (string) $allocated->fallbackReason
        );
        $this->assertSame(
            substr((string) $allocated->fallbackReason, strlen('panelalpha_online: ')),
            DomainAllocator::lastOnlineError()['message'] ?? null
        );
        Http::assertSentCount(1);
    }

    public function test_a_create_that_timed_out_waiting_for_an_answer_logs_the_label_it_gave_up_on(): void
    {
        $this->engine();
        $label = $this->connectFailsWith(fn (Request $request) => new NetworkTimeoutException(
            'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received',
            $request->toPsrRequest()
        ));
        Log::spy();

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_DIRECT, $allocated->source);
        $this->assertMatchesRegularExpression('/^shop-[0-9a-f]{4}$/', (string) $label->path);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, "{$label->path}.panelalpha.online") && str_contains($message, 'may hold that label')
        );
    }

    public function test_a_connect_timeout_reached_nothing_so_no_label_is_logged(): void
    {
        $this->engine();
        $this->connectFailsWith(fn (Request $request) => new ConnectTimeoutException(
            'cURL error 28: Connection timed out after 10001 milliseconds',
            $request->toPsrRequest()
        ));
        Log::spy();

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_DIRECT, $allocated->source);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message): bool => str_starts_with($message, 'PanelAlpha Online allocation unavailable:')
        );
    }

    /** Connect fails every request with the transport error $make builds; the path last asked for is kept. */
    private function connectFailsWith(callable $make): \stdClass
    {
        $label = new \stdClass();
        $label->path = null;
        Http::fake(['*' => function (Request $request) use ($label, $make) {
            $label->path = (string) $request['path'];

            return Create::rejectionFor($make($request));
        }]);

        return $label;
    }

    public function test_a_requested_online_name_with_connect_unreachable_is_refused_as_not_allocated(): void
    {
        $this->engine();
        Http::fake(['*' => Http::failedConnection()]);

        try {
            (new DomainAllocator)->allocate('mine', 'mine.panelalpha.online');
            $this->fail('Expected the requested name to be refused.');
        } catch (DomainAllocationException $e) {
            $this->assertStringStartsWith(
                'PanelAlpha Online create failed: PanelAlpha Connect could not be reached (',
                $e->getMessage()
            );
            $this->assertSame('allocation_failed', $e->reason);
        }
    }

    public function test_a_requested_online_name_is_bought_under_its_own_label(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::sold('mine-ef56'), 200)]);

        $allocated = (new DomainAllocator)->allocate('mine', 'mine.panelalpha.online', DomainPlan::TUNNEL_PANELALPHA);

        $this->assertSame([
            'source' => DomainPlan::SOURCE_REQUESTED,
            'tunnel' => 'panelalpha',
            'public' => true,
            'tls' => 'proxy',
            'reason' => null,
        ], self::shape($allocated));
        $this->assertSame('mine-ef56.panelalpha.online', $allocated->domain);
        Http::assertSent(fn (Request $request) => $request['path'] === 'mine');
    }

    public function test_a_requested_online_name_already_on_this_engine_is_refused(): void
    {
        $this->engine();
        $this->holdLocally('mine.panelalpha.online');
        Http::fake();

        try {
            (new DomainAllocator)->allocate('mine', 'mine.panelalpha.online');
            $this->fail('Expected the requested name to be refused.');
        } catch (DomainAllocationException $e) {
            $this->assertSame('mine.panelalpha.online is already on this engine.', $e->getMessage());
            $this->assertSame('hostname_taken', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_a_requested_online_name_taken_at_the_proxy_is_refused(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::refused('The domain name is not available'), 400)]);

        try {
            (new DomainAllocator)->allocate('mine', 'mine.panelalpha.online');
            $this->fail('Expected the requested name to be refused.');
        } catch (DomainAllocationException $e) {
            $this->assertSame(
                'mine.panelalpha.online is already taken. Labels under panelalpha.online are first come, first served across every engine.',
                $e->getMessage()
            );
            $this->assertSame('hostname_taken', $e->reason);
        }
    }

    public function test_a_requested_online_name_the_proxy_will_not_sell_carries_its_error(): void
    {
        $this->engine();
        Http::fake([self::PROXY => Http::response(self::refused('License expired'), 200)]);

        try {
            (new DomainAllocator)->allocate('mine', 'mine.panelalpha.online');
            $this->fail('Expected the requested name to be refused.');
        } catch (DomainAllocationException $e) {
            $this->assertSame('PanelAlpha Online create failed: License expired (HTTP 200).', $e->getMessage());
            $this->assertSame('allocation_failed', $e->reason);
        }
    }

    public function test_tunnel_none_skips_the_proxy_and_a_held_base_name_is_suffixed(): void
    {
        $this->engine(['default_wildcard_domain' => 'sites.example.com']);
        $this->holdLocally('shop.sites.example.com');
        Http::fake();

        $allocated = (new DomainAllocator)->allocate('shop', null, DomainPlan::TUNNEL_NONE);

        $this->assertSame(DomainPlan::SOURCE_SITES_BASE_DOMAIN, $allocated->source);
        $this->assertNull($allocated->publiclyResolvable);
        $this->assertNull($allocated->fallbackReason);
        $this->assertMatchesRegularExpression('/^shop-[0-9a-f]{4}\.sites\.example\.com$/', $allocated->domain);
        Http::assertNothingSent();
    }

    public function test_a_private_engine_lands_on_the_dashed_address_with_the_reason(): void
    {
        $this->engine(['default_ipv4' => TestNetwork::PRIVATE_10]);
        Http::fake();

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame([
            'source' => DomainPlan::SOURCE_PANELALPHA_DIRECT,
            'tunnel' => null,
            'public' => false,
            'tls' => 'engine',
            'reason' => "no public IPv4: the host's address is private, so panelalpha.online could not serve it",
        ], self::shape($allocated));
        Http::assertNothingSent();
    }

    public function test_an_engine_with_no_address_lands_on_local(): void
    {
        $this->engine(['default_ipv4' => null]);
        Http::fake();

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame('shop.local', $allocated->domain);
        $this->assertSame(DomainPlan::SOURCE_LOCAL, $allocated->source);
        $this->assertFalse($allocated->publiclyResolvable);
        $this->assertSame(
            'no public IPv4: the host has no IPv4 address on record, so neither panelalpha.online nor panelalpha.direct could give it a name at all',
            $allocated->fallbackReason
        );
    }

    public function test_a_requested_plain_domain_is_handed_back_unchecked(): void
    {
        $this->engine();
        $this->holdLocally('shop.example.org');
        Http::fake();

        $allocated = (new DomainAllocator)->allocate('shop', 'Shop.Example.org.');

        $this->assertSame('shop.example.org', $allocated->domain);
        $this->assertSame(DomainPlan::SOURCE_REQUESTED, $allocated->source);
        $this->assertNull($allocated->publiclyResolvable);
        $this->assertNull($allocated->fallbackReason);
        Http::assertNothingSent();
    }
}
