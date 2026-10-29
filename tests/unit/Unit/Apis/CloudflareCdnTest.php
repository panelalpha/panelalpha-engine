<?php

namespace Tests\Unit\Apis;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Cache purges and zone settings: the three calls the CDN endpoints make. */
class CloudflareCdnTest extends TestCase
{
    private function client(): Cloudflare
    {
        return new Cloudflare('cf-token-not-real');
    }

    public function test_purging_everything_sends_purge_everything(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'zone-id']])]);

        $this->client()->purgeCache('zone-id', true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache'
            && $request['purge_everything'] === true);
    }

    public function test_purging_files_sends_the_list(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'zone-id']])]);

        $this->client()->purgeCache('zone-id', false, ['https://shop.example.test/style.css']);

        Http::assertSent(fn (Request $request): bool => $request['files'] === ['https://shop.example.test/style.css']
            && !isset($request['purge_everything']));
    }

    public function test_a_zone_setting_is_read_back(): void
    {
        Http::fake(['*/zones/zone-id/settings/security_level' => Http::response([
            'success' => true,
            'result' => ['id' => 'security_level', 'value' => 'high'],
        ])]);

        $this->assertSame('high', $this->client()->getSetting('zone-id', 'security_level'));
    }

    public function test_a_zone_setting_is_written_with_patch(): void
    {
        Http::fake(['*/zones/zone-id/settings/security_level' => Http::response([
            'success' => true,
            'result' => ['id' => 'security_level', 'value' => 'under_attack'],
        ])]);

        $value = $this->client()->setSetting('zone-id', 'security_level', 'under_attack');

        $this->assertSame('under_attack', $value);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request['value'] === 'under_attack');
    }

    public function test_a_forbidden_response_carries_its_status(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']],
        ], 403)]);

        try {
            $this->client()->purgeCache('zone-id', true);
            $this->fail('a 403 must raise');
        } catch (CloudflareException $e) {
            $this->assertTrue($e->isPermissionDenied());
            $this->assertFalse($e->isInvalidToken());
            $this->assertSame(403, $e->responseStatus());
        }
    }

    /** @return array<string, array{int, array<string, mixed>}> */
    public static function tokensCloudflareDoesNotAccept(): array
    {
        return [
            'invalid or revoked' => [403, ['code' => 9109, 'message' => 'Invalid access token']],
            'malformed' => [400, ['code' => 6003, 'message' => 'Invalid request headers', 'error_chain' => [
                ['code' => 6111, 'message' => 'Invalid format for Authorization header'],
            ]]],
            'missing' => [400, ['code' => 9106, 'message' => 'Missing X-Auth-Key, X-Auth-Email or Authorization headers']],
            'unauthenticated' => [401, ['code' => 1000, 'message' => 'Invalid API Token']],
        ];
    }

    /** @param array<string, mixed> $error */
    #[DataProvider('tokensCloudflareDoesNotAccept')]
    public function test_a_token_cloudflare_does_not_accept_is_told_apart_from_a_missing_permission(int $status, array $error): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [$error]], $status)]);

        try {
            $this->client()->findZoneForHostname('shop.example.test');
            $this->fail('a refused token must raise');
        } catch (CloudflareException $e) {
            $this->assertTrue($e->isInvalidToken());
        }
    }
}
