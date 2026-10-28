<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Tunnel;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST …/tunnels with the cloudflare provider when Cloudflare refuses or fails the zone lookup:
 * the codes and statuses the CDN endpoints answer, and no tunnel made.
 */
class CloudflareTunnelHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const ZONES = 'api.cloudflare.com/client/v4/zones?*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $alice = $this->makeUser('alice', [
            'template' => 'dind',
            'cloudflare_api_token' => 'cf-token-not-real',
            'cloudflare_account_id' => 'acc',
        ], 'shop.example.test');
        $this->makeMainDomain($alice, 'shop.example.test');
    }

    public function test_a_token_that_cannot_read_the_zone_is_422_naming_the_permission(): void
    {
        Http::fake([self::ZONES => self::refusal(403, 9109, 'Unauthorized to access requested resource')]);

        $response = $this->attach()->assertUnprocessable();

        $this->assertSame('cloudflare_api_token', $response->json('problems.0.field'));
        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone · Zone · Read', (string) $response->json('problems.0.message'));
        $this->assertNothingWasMade();
    }

    public function test_a_token_cloudflare_does_not_accept_is_422_cloudflare_token_invalid(): void
    {
        Http::fake([self::ZONES => self::refusal(401, 1000, 'Invalid API Token')]);

        $response = $this->attach()->assertUnprocessable();

        $this->assertSame('cloudflare_token_invalid', $response->json('problems.0.code'));
        $this->assertNothingWasMade();
    }

    public function test_a_rate_limited_token_is_503(): void
    {
        Http::fake([self::ZONES => self::refusal(429, 971, 'Please wait and consider throttling your request speed')]);

        $this->attach()->assertStatus(503)->assertJsonPath('code', 'cloudflare_rate_limited');
        $this->assertNothingWasMade();
    }

    public function test_a_cloudflare_outage_is_502(): void
    {
        Http::fake([self::ZONES => self::refusal(500, 10000, 'Internal Server Error')]);

        $this->attach()->assertStatus(502)->assertJsonPath('code', 'cloudflare_unavailable');
        $this->assertNothingWasMade();
    }

    private function attach(): TestResponse
    {
        return $this->postJson('/api/projects/alice/domains/shop.example.test/tunnels', [
            'hostname' => 'www.example.test',
            'provider' => 'cloudflare',
        ]);
    }

    private function assertNothingWasMade(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cfd_tunnel'));
        $this->assertSame(0, Tunnel::query()->count());
    }

    private static function refusal(int $status, int $code, string $message): PromiseInterface
    {
        return Http::response(['success' => false, 'errors' => [['code' => $code, 'message' => $message]]], $status);
    }
}
