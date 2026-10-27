<?php

namespace Tests\Unit\WordpressMigration;

use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Lib\Http\OutboundUrl;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use App\System\Project\PhpHosting\WordPress\Migration\TransferAddress;
use Illuminate\Support\Facades\Queue;

/**
 * The source sends the transfer to the engine's default IPv4, so an engine
 * whose address is private refuses an import before anything is stored or
 * installed on the source, and its connection test says so.
 */
class WordpressMigrationTransferAddressTest extends ImportTargetTestCase
{
    private const PRIVATE_IPV4 = '10.10.10.25';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Queue::fake();
        putenv('WORDPRESS_MIGRATE_PASSWORD=secret');
        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => ['93.184.216.34'],
        );
    }

    protected function tearDown(): void
    {
        OutboundUrl::setPolicyForTests(null, null);
        putenv('WORDPRESS_MIGRATE_PASSWORD');
        parent::tearDown();
    }

    public function test_a_start_on_a_private_engine_address_is_409_naming_it_and_stores_nothing(): void
    {
        $this->engineAddress(self::PRIVATE_IPV4);

        $response = $this->postJson('/api/wordpress/migrations', $this->body());

        $response->assertStatus(409);
        $this->assertSame(TransferAddress::UNREACHABLE, $response->json('problems.0.code'));
        $this->assertStringContainsString(self::PRIVATE_IPV4, (string) $response->json('problems.0.message'));
        $this->assertSame(0, WordpressMigration::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_wordpress_migrate_on_a_private_engine_address_fails_naming_it(): void
    {
        $this->engineAddress(self::PRIVATE_IPV4);

        $this->artisan('wordpress:migrate', ['url' => 'https://example.com', 'username' => 'admin'])
            ->expectsOutputToContain(self::PRIVATE_IPV4)
            ->assertFailed();

        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_the_connection_test_answers_engine_address_private(): void
    {
        $this->engineAddress(self::PRIVATE_IPV4);
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $this->wordpress()));

        $this->postJson('/api/wordpress/migrations/test', $this->body())
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_type', TransferAddress::UNREACHABLE);
    }

    public function test_a_public_engine_address_starts_and_its_test_passes(): void
    {
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $this->wordpress()));

        $this->postJson('/api/wordpress/migrations/test', $this->body())->assertJsonPath('ok', true);
        $this->postJson('/api/wordpress/migrations', $this->body())->assertStatus(202);
    }

    /** Behind 1:1 NAT the default IPv4 is the local address; the source is sent the public one it maps to, as DNS is. */
    public function test_a_private_engine_address_mapped_to_a_public_one_starts_and_sends_the_public_one(): void
    {
        $this->engineAddress(self::PRIVATE_IPV4);
        Ipv4NatMap::upsertMap(self::PRIVATE_IPV4, '93.184.216.40');
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $this->wordpress()));

        $this->assertSame('93.184.216.40', TransferAddress::ipv4());
        $this->postJson('/api/wordpress/migrations/test', $this->body())->assertJsonPath('ok', true);
        $this->postJson('/api/wordpress/migrations', $this->body())->assertStatus(202);
    }

    public function test_a_private_engine_address_mapped_to_another_private_one_is_still_refused_naming_that_one(): void
    {
        $this->engineAddress(self::PRIVATE_IPV4);
        Ipv4NatMap::upsertMap(self::PRIVATE_IPV4, '192.168.1.10');

        $response = $this->postJson('/api/wordpress/migrations', $this->body());

        $response->assertStatus(409);
        $this->assertStringContainsString('192.168.1.10', (string) $response->json('problems.0.message'));
    }

    public function test_an_engine_without_an_address_on_record_still_starts(): void
    {
        $this->engineAddress('');

        $this->postJson('/api/wordpress/migrations', $this->body())->assertStatus(202);
    }

    public function test_egress_allow_private_lets_a_private_address_start_but_never_a_reserved_one(): void
    {
        OutboundUrl::setPolicyForTests(true, null);

        $this->engineAddress(self::PRIVATE_IPV4);
        $this->postJson('/api/wordpress/migrations', $this->body())->assertStatus(202);

        $this->engineAddress('127.0.0.1');
        $this->postJson('/api/wordpress/migrations', $this->body(['domain' => 'other.example.org']))
            ->assertStatus(409)
            ->assertJsonPath('problems.0.code', TransferAddress::UNREACHABLE);
    }

    private function engineAddress(string $ipv4): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => $ipv4]);
    }

    private function wordpress(): RoutedSourceHttpClient
    {
        $home = 'https://example.com';

        return (new RoutedSourceHttpClient())
            ->on('GET', $home . '/wp-login.php', RoutedSourceHttpClient::loginPage($home, $home . '/wp-login.php'))
            ->on('GET', $home . '/wp-json/', RoutedSourceHttpClient::restIndex($home))
            ->on('POST', $home . '/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', $home . '/wp-admin', RoutedSourceHttpClient::admin());
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + ['url' => 'https://example.com', 'username' => 'admin', 'password' => 'secret'];
    }
}
