<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\User;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A stored tunnel token that cannot be decoded (APP_KEY changed) is never
 * replaced by a background sync: no lookup, no new tunnel, no write.
 */
class CloudflareUnreadableTunnelTokenTest extends TestCase
{
    private function useKey(string $char): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat($char, 32))]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstance('encrypter');
    }

    /** API token readable under the current key, tunnel token written under an older one. */
    private function user(): User
    {
        $this->useKey('k');
        $old = new User();
        $old->details = ['cloudflare_tunnel_token' => 'old-tunnel-token'];
        $stale = json_decode((string) $old->getAttributes()['details'], true);

        $this->useKey('x');
        $user = new User();
        $user->username = 'fx468';
        $user->details = [
            'cloudflare_api_token' => 'cf-api',
            'cloudflare_account_id' => 'acc',
            'cloudflare_tunnel_id' => 'tun',
        ];
        $details = json_decode((string) $user->getAttributes()['details'], true);
        $user->setRawAttributes(['username' => 'fx468', 'details' => json_encode($details + $stale)]);

        return $user;
    }

    public function test_an_unreadable_tunnel_token_is_refused_and_kept(): void
    {
        Http::fake();
        Log::spy();
        $user = $this->user();
        $before = $user->getAttributes()['details'];
        $this->assertTrue($user->hasUnreadableSecret('cloudflare_tunnel_token'));

        try {
            Cloudflare::ensureTunnel($user);
            $this->fail('ensureTunnel went ahead with an unreadable tunnel token');
        } catch (CloudflareException $e) {
            $this->assertStringContainsString('cannot be decoded', $e->getMessage());
            $this->assertStringContainsString('project:settings:unset --project=fx468 cloudflare-api-token --force', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame($before, $user->getAttributes()['details']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'cannot be decoded'));
    }

    /** A token that was never stored is still looked up by name, as before. */
    public function test_a_missing_tunnel_token_is_still_looked_up(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['code' => 1, 'message' => 'nope']]], 403)]);
        $this->useKey('x');
        $user = new User();
        $user->username = 'fx468';
        $user->details = ['cloudflare_api_token' => 'cf-api', 'cloudflare_account_id' => 'acc'];

        try {
            Cloudflare::ensureTunnel($user);
        } catch (CloudflareException $e) {
            $this->assertStringNotContainsString('cannot be decoded', $e->getMessage());
        }

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/accounts/acc/cfd_tunnel'));
    }
}
