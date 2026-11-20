<?php

namespace Tests\Integration\Domains;

use App\Lib\Domains\DomainAllocationException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The allocator when every name it could try is already held on this engine, and when the setting it remembers the
 * proxy's last refusal in cannot be read or written.
 */
class DomainAllocatorExhaustedTest extends TestCase
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

    public function test_an_online_label_held_here_is_not_offered_to_the_proxy(): void
    {
        $this->engine(['default_ipv4' => TestNetwork::public(9)]);
        $this->holdEverySuffix('shop', '.'.DomainPlan::PANELALPHA_ONLINE);
        Http::fake([self::PROXY => Http::response(self::sold('shop-ab12-cd34'), 200)]);

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame('shop-ab12-cd34.panelalpha.online', $allocated->domain);
        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_ONLINE, $allocated->source);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => preg_match('/^shop-[0-9a-f]{4}-[0-9a-f]{4}$/', (string) $request['path']) === 1);
    }

    public function test_an_engine_whose_every_name_is_held_has_no_domain_to_give(): void
    {
        $this->engine([DomainPlan::SETTING_DEFAULT_TUNNEL => DomainPlan::TUNNEL_NONE]);
        $this->holdEverySuffix('shop', '.local', 'shop.local');

        try {
            (new DomainAllocator)->allocate('shop');
            $this->fail('a held name was given out');
        } catch (DomainAllocationException $e) {
            $this->assertSame("No domain could be chosen for project 'shop'.", $e->getMessage());
            $this->assertSame('no_domain_available', $e->reason);
        }
    }

    public function test_a_last_error_that_cannot_be_read_is_no_error(): void
    {
        Setting::clearRuntimeSettings();
        Schema::drop('settings');

        $this->assertNull(DomainAllocator::lastOnlineError());
    }

    public function test_a_sale_goes_through_when_the_last_error_cannot_be_cleared(): void
    {
        Setting::setRuntimeSettings([
            'license_key' => 'K',
            'default_wildcard_domain' => null,
            'cert_domain' => null,
            'default_ipv4' => TestNetwork::public(9),
            DomainPlan::SETTING_DEFAULT_TUNNEL => null,
        ]);
        Schema::drop('settings');
        Http::fake([self::PROXY => Http::response(self::sold('shop-ab12'), 200)]);
        Log::spy();

        $allocated = (new DomainAllocator)->allocate('shop');

        $this->assertSame('shop-ab12.panelalpha.online', $allocated->domain);
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_starts_with($message, 'Could not record the PanelAlpha Online error: '))->once();
    }

    /** @return array<string, mixed> */
    private static function sold(string $label): array
    {
        return ['status' => 'success', 'data' => ['path' => $label.'.'.DomainPlan::PANELALPHA_ONLINE, 'model' => ['id' => 7]]];
    }

    /** @param array<string, ?string> $overrides */
    private function engine(array $overrides): void
    {
        Setting::setRuntimeSettings($overrides + [
            'license_key' => 'K',
            'default_wildcard_domain' => null,
            'cert_domain' => null,
            'default_ipv4' => null,
            DomainPlan::SETTING_DEFAULT_TUNNEL => null,
            DomainAllocator::SETTING_ONLINE_LAST_ERROR => '',
        ]);
    }

    /** Tunnel rows for `<label>-<every four hex digits><parent>`, and $also, so no random suffix is free. */
    private function holdEverySuffix(string $label, string $parent, string ...$also): void
    {
        $holder = $this->makeUser('holder', [], 'holder.example.test');
        $domain = $this->makeMainDomain($holder, 'holder.example.test');
        $names = [...$also];
        for ($suffix = 0; $suffix <= 0xFFFF; $suffix++) {
            $names[] = sprintf('%s-%04x%s', $label, $suffix, $parent);
        }
        foreach (array_chunk($names, 1000) as $chunk) {
            DB::table('tunnels')->insert(array_map(static fn (string $name): array => [
                'domain_id' => $domain->id,
                'user_id' => $holder->id,
                'provider' => Tunnel::PROVIDER_PANELALPHA,
                'hostname' => $name,
            ], $chunk));
        }
    }
}
