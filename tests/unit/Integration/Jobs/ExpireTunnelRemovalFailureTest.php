<?php

namespace Tests\Integration\Jobs;

use App\Jobs\ExpireTunnel;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** An expired tunnel whose removal fails part way, here in the sibling domain's rebuild, is logged, not raised. */
class ExpireTunnelRemovalFailureTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['license_key' => 'K', 'default_ipv4' => TestNetwork::public(10)]);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        $this->dir = sys_get_temp_dir() . '/pa-expire-tunnel-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $client = $this->dir . '/pa-root';
        file_put_contents($client, "#!/bin/sh\necho 'Operation not permitted' >&2\nexit 1\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        Setting::clearRuntimeSettings();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_removal_that_fails_is_logged_with_the_hostname(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);
        $shop = $this->makeUser('shop', [], 'shop.example.test');
        $domain = $this->makeMainDomain($shop, 'shop.example.test');
        $tunnel = Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $shop->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop-preview.panelalpha.online',
            'details' => ['purpose' => Tunnel::PURPOSE_PREVIEW, 'expires_at' => now()->subMinute()->utc()->toIso8601String()],
        ]);
        Log::spy();

        (new ExpireTunnel($tunnel->id))->handle();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_starts_with($message, 'Tunnel shop-preview.panelalpha.online is past its lifetime but was not removed: '),
        )->once();
        $this->assertNull($tunnel->fresh());
    }
}
