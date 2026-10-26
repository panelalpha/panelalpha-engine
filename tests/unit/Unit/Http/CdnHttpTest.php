<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `…/domains/{domain}/cdn`: Cloudflare status, purge and security level over Http::fake. */
class CdnHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const ZONE_URL = 'api.cloudflare.com/client/v4/zones?*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $alice = $this->makeUser('alice', ['cloudflare_api_token' => 'cf-token-not-real'], 'shop.example.test');
        $this->makeMainDomain($alice, 'shop.example.test');
    }

    public function test_get_maps_the_zone_and_settings(): void
    {
        $this->fakeCdn();

        $data = $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')->assertOk()->json('data');

        $this->assertSame('cloudflare', $data['provider']);
        $this->assertSame('zone-id', $data['zone_id']);
        $this->assertSame('example.test', $data['zone_name']);
        $this->assertTrue($data['proxied']);
        $this->assertSame('medium', $data['security_level']);
        $this->assertFalse($data['development_mode']);
    }

    public function test_get_reports_development_mode_as_on(): void
    {
        $this->fakeCdn(['development_mode' => 'on']);

        $data = $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')->assertOk()->json('data');

        $this->assertTrue($data['development_mode']);
    }

    public function test_purge_everything_sends_purge_everything(): void
    {
        $this->fakeCdn();

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertOk()
            ->assertJsonPath('data.purged', true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache'
            && $request['purge_everything'] === true);
    }

    public function test_purge_files_sends_the_list(): void
    {
        $this->fakeCdn();

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', [
            'files' => ['https://shop.example.test/style.css'],
        ])->assertOk()->assertJsonPath('data.purged', true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache'
            && ($request['files'] ?? null) === ['https://shop.example.test/style.css']
            && !isset($request['purge_everything']));
    }

    public function test_security_level_sets_the_value(): void
    {
        $this->fakeCdn(['security_level' => 'under_attack']);

        $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'under_attack'])
            ->assertOk()
            ->assertJsonPath('data.security_level', 'under_attack');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request['value'] === 'under_attack');
    }

    public function test_an_unknown_security_level_is_refused(): void
    {
        $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'paranoid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    public function test_a_missing_token_is_refused(): void
    {
        $bob = $this->makeUser('bob', [], 'bob.example.test');
        $this->makeMainDomain($bob, 'bob.example.test');

        $response = $this->getJson('/api/projects/bob/domains/bob.example.test/cdn')->assertUnprocessable();

        $this->assertSame('cloudflare_token_missing', $response->json('problems.0.code'));
    }

    public function test_a_forbidden_zone_lookup_names_the_settings_scope(): void
    {
        Http::fake([self::ZONE_URL => self::forbidden()]);

        $response = $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')->assertUnprocessable();

        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone Settings Read', (string) $response->json('problems.0.message'));
    }

    public function test_purge_without_the_cache_purge_scope_names_it(): void
    {
        $this->fakeCdn(['purge_cache' => self::forbidden()]);

        $response = $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertUnprocessable();

        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone Cache Purge', (string) $response->json('problems.0.message'));
    }

    public function test_security_level_without_the_settings_edit_scope_names_it(): void
    {
        $this->fakeCdn(['settings' => self::forbidden()]);

        $response = $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'high'])
            ->assertUnprocessable();

        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone Settings Edit', (string) $response->json('problems.0.message'));
    }

    public function test_more_than_thirty_files_is_refused(): void
    {
        $files = array_map(static fn (int $i): string => "https://shop.example.test/file-{$i}.css", range(1, 31));

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['files' => $files])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');
    }

    public function test_an_empty_purge_body_is_refused(): void
    {
        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');
    }

    public function test_a_domain_outside_the_token_zones_is_404_with_a_code(): void
    {
        Http::fake([self::ZONE_URL => Http::response([
            'success' => true,
            'result' => [['id' => 'other-zone', 'name' => 'other.test']],
        ])]);

        $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')
            ->assertNotFound()
            ->assertJsonPath('code', 'zone_not_found');
    }

    public function test_an_unknown_domain_is_404(): void
    {
        $this->getJson('/api/projects/alice/domains/missing.example.test/cdn')->assertNotFound();
    }

    public function test_an_unknown_project_is_404(): void
    {
        $this->getJson('/api/projects/nobody/domains/shop.example.test/cdn')->assertNotFound();
    }

    /**
     * @param array{development_mode?: string, security_level?: string, purge_cache?: PromiseInterface, settings?: PromiseInterface} $options
     */
    private function fakeCdn(array $options = []): void
    {
        Http::fake([
            self::ZONE_URL => Http::response([
                'success' => true,
                'result' => [['id' => 'zone-id', 'name' => 'example.test']],
            ]),
            '*/dns_records*' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'record-id',
                    'type' => 'A',
                    'name' => 'shop.example.test',
                    'content' => '192.0.2.10',
                    'proxied' => true,
                ]],
            ]),
            '*/settings/security_level' => $options['settings']
                ?? self::setting('security_level', $options['security_level'] ?? 'medium'),
            '*/settings/development_mode' => $options['settings']
                ?? self::setting('development_mode', $options['development_mode'] ?? 'off'),
            '*/purge_cache' => $options['purge_cache'] ?? Http::response([
                'success' => true,
                'result' => ['id' => 'zone-id'],
            ]),
        ]);
    }

    private static function setting(string $name, string $value): PromiseInterface
    {
        return Http::response([
            'success' => true,
            'result' => ['id' => $name, 'value' => $value],
        ]);
    }

    private static function forbidden(): PromiseInterface
    {
        return Http::response([
            'success' => false,
            'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']],
        ], 403);
    }
}
