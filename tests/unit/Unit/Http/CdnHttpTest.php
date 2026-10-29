<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Domain;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `…/domains/{domain}/cdn`: Cloudflare status, purge and security level over Http::fake. */
class CdnHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const ZONE_URL = 'api.cloudflare.com/client/v4/zones?*';

    private const TWO_ZONES = [
        ['id' => 'zone-id', 'name' => 'example.test'],
        ['id' => 'zone-net', 'name' => 'example.net'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
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
            && ! isset($request['purge_everything']));
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

    public function test_an_alias_changes_the_security_level_of_its_own_zone(): void
    {
        $this->aliasShopAs('shop.example.net');
        $this->fakeCdn(['security_level' => 'under_attack', 'zones' => self::TWO_ZONES]);

        $this->putJson('/api/projects/alice/domains/shop.example.net/cdn/security-level', ['level' => 'under_attack'])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-net/settings/security_level');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/zones/zone-id/'));
    }

    public function test_the_status_of_an_alias_reads_its_own_zone_and_record(): void
    {
        $this->aliasShopAs('shop.example.net');
        $this->fakeCdn(['zones' => self::TWO_ZONES]);

        $this->getJson('/api/projects/alice/domains/shop.example.net/cdn')
            ->assertOk()
            ->assertJsonPath('data.zone_id', 'zone-net')
            ->assertJsonPath('data.zone_name', 'example.net');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/zones/zone-net/dns_records')
            && ($request->data()['name'] ?? null) === 'shop.example.net');
    }

    public function test_an_alias_outside_the_token_zones_is_404_naming_the_alias(): void
    {
        $this->aliasShopAs('shop.example.net');
        $this->fakeCdn();

        $this->postJson('/api/projects/alice/domains/shop.example.net/cdn/purge', ['everything' => true])
            ->assertNotFound()
            ->assertJsonPath('code', 'zone_not_found')
            ->assertJsonPath('message', "No Cloudflare zone for 'shop.example.net' is visible to this API token.");
    }

    public function test_a_purge_of_another_projects_url_is_refused_before_cloudflare_is_called(): void
    {
        $this->makeOtherProject('blog.example.test');
        $this->fakeCdn();

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', [
            'files' => ['https://shop.example.test/style.css', 'https://blog.example.test/style.css'],
        ])->assertUnprocessable()->assertJsonValidationErrors('files.1')->assertJsonMissingValidationErrors('files.0');

        Http::assertNothingSent();
    }

    public function test_a_purge_of_a_url_on_an_alias_is_allowed(): void
    {
        $this->aliasShopAs('www.shop.example.test');
        $this->fakeCdn();

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', [
            'files' => ['https://WWW.shop.example.test/style.css'],
        ])->assertOk();
    }

    public function test_purging_everything_is_refused_while_another_project_shares_the_zone(): void
    {
        $this->makeOtherProject('blog.example.test');
        $this->fakeCdn();

        $response = $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertConflict();

        $this->assertSame('cloudflare_zone_shared', $response->json('problems.0.code'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/purge_cache'));
    }

    public function test_purging_own_files_still_works_while_another_project_shares_the_zone(): void
    {
        $this->makeOtherProject('blog.example.test');
        $this->fakeCdn();

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', [
            'files' => ['https://shop.example.test/style.css'],
        ])->assertOk();
    }

    public function test_the_security_level_is_refused_while_another_project_shares_the_zone(): void
    {
        $this->makeOtherProject('blog.example.test');
        $this->fakeCdn();

        $response = $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'off'])
            ->assertConflict();

        $this->assertSame('cloudflare_zone_shared', $response->json('problems.0.code'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_the_projects_own_staging_copy_does_not_block_whole_zone_actions(): void
    {
        $alice = User::findByUsername('alice');
        $this->assertNotNull($alice);
        $staging = $this->makeUser('alicestg', [], 'staging.shop.example.test');
        $staging->staging = $alice->id;
        $staging->save();
        $this->makeMainDomain($staging, 'staging.shop.example.test');
        $this->fakeCdn(['security_level' => 'under_attack']);

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertOk();
        $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'under_attack'])
            ->assertOk()
            ->assertJsonPath('data.security_level', 'under_attack');
    }

    public function test_another_project_in_a_different_zone_does_not_share_it(): void
    {
        $this->makeOtherProject('blog.other.test');
        $this->fakeCdn(['security_level' => 'high']);

        $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'high'])
            ->assertOk();
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

    public function test_a_forbidden_zone_lookup_names_the_zone_read_scope(): void
    {
        Http::fake([self::ZONE_URL => self::forbidden()]);

        $response = $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertUnprocessable();

        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone Read', (string) $response->json('problems.0.message'));
        $this->assertStringNotContainsString('Cache Purge', (string) $response->json('problems.0.message'));
    }

    public function test_an_invalid_or_revoked_token_is_named_as_such_not_as_a_missing_permission(): void
    {
        Http::fake([self::ZONE_URL => self::failure(403, 'Invalid access token')]);

        foreach ([
            fn () => $this->getJson('/api/projects/alice/domains/shop.example.test/cdn'),
            fn () => $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true]),
            fn () => $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'high']),
        ] as $call) {
            $response = $call()->assertUnprocessable();

            $this->assertSame('cloudflare_api_token', $response->json('problems.0.field'));
            $this->assertSame('cloudflare_token_invalid', $response->json('problems.0.code'));
            $this->assertStringContainsString('invalid or revoked', (string) $response->json('problems.0.message'));
            $this->assertStringContainsString('Invalid access token', (string) $response->json('problems.0.message'));
            $this->assertStringNotContainsString('Zone Read', (string) $response->json('problems.0.message'));
        }
    }

    public function test_a_token_revoked_between_the_zone_lookup_and_the_purge_is_named_as_such(): void
    {
        $this->fakeCdn(['purge_cache' => self::failure(403, 'Invalid access token')]);

        $response = $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertUnprocessable();

        $this->assertSame('cloudflare_token_invalid', $response->json('problems.0.code'));
    }

    public function test_a_rate_limited_zone_lookup_is_503_not_a_missing_zone(): void
    {
        Http::fake([self::ZONE_URL => self::failure(429, 'Rate limited. Please wait and consider throttling your request speed')]);

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertStatus(503)
            ->assertJsonPath('code', 'cloudflare_rate_limited');
    }

    public function test_a_rate_limited_purge_is_503(): void
    {
        $this->fakeCdn(['purge_cache' => self::failure(429, 'Rate limited')]);

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertStatus(503)
            ->assertJsonPath('code', 'cloudflare_rate_limited');
    }

    public function test_a_cloudflare_outage_on_the_zone_lookup_is_502_not_a_missing_zone(): void
    {
        Http::fake([self::ZONE_URL => self::failure(503, 'Service unavailable')]);

        $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')
            ->assertStatus(502)
            ->assertJsonPath('code', 'cloudflare_unavailable');
    }

    public function test_a_cloudflare_outage_on_the_security_level_is_502(): void
    {
        $this->fakeCdn(['settings' => self::failure(500, 'Internal server error')]);

        $this->putJson('/api/projects/alice/domains/shop.example.test/cdn/security-level', ['level' => 'under_attack'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'cloudflare_unavailable');
    }

    public function test_an_answer_that_is_not_json_is_502(): void
    {
        $this->fakeCdn(['purge_cache' => Http::response('<html>Bad gateway</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->postJson('/api/projects/alice/domains/shop.example.test/cdn/purge', ['everything' => true])
            ->assertStatus(502)
            ->assertJsonPath('code', 'cloudflare_unavailable');
    }

    public function test_an_unreachable_cloudflare_is_502(): void
    {
        Http::fake([self::ZONE_URL => Http::failedConnection('cURL error 28: Operation timed out')]);

        $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')
            ->assertStatus(502)
            ->assertJsonPath('code', 'cloudflare_unavailable');
    }

    public function test_a_request_cloudflare_refuses_is_422_with_its_message(): void
    {
        Http::fake([self::ZONE_URL => self::failure(400, 'Invalid API Token')]);

        $response = $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')->assertUnprocessable();

        $this->assertSame('cloudflare_request_refused', $response->json('problems.0.code'));
        $this->assertStringContainsString('Invalid API Token', (string) $response->json('problems.0.message'));
    }

    public function test_a_token_that_sees_no_zones_is_404_with_a_code(): void
    {
        Http::fake([self::ZONE_URL => Http::response(['success' => true, 'result' => []])]);

        $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')
            ->assertNotFound()
            ->assertJsonPath('code', 'zone_not_found');
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

    public function test_status_without_the_settings_read_scope_names_it(): void
    {
        $this->fakeCdn(['settings' => self::forbidden()]);

        $response = $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')->assertUnprocessable();

        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone Settings Read', (string) $response->json('problems.0.message'));
    }

    public function test_status_without_the_dns_read_scope_names_it(): void
    {
        $this->fakeCdn(['dns_records' => self::forbidden()]);

        $response = $this->getJson('/api/projects/alice/domains/shop.example.test/cdn')->assertUnprocessable();

        $this->assertSame('cloudflare_permission_denied', $response->json('problems.0.code'));
        $this->assertStringContainsString('Zone DNS Read', (string) $response->json('problems.0.message'));
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
     * @param  array{development_mode?: string, security_level?: string, purge_cache?: PromiseInterface, settings?: PromiseInterface, dns_records?: PromiseInterface, zones?: list<array{id: string, name: string}>}  $options
     */
    private function fakeCdn(array $options = []): void
    {
        Http::fake([
            self::ZONE_URL => Http::response([
                'success' => true,
                'result' => $options['zones'] ?? [['id' => 'zone-id', 'name' => 'example.test']],
            ]),
            '*/dns_records*' => $options['dns_records'] ?? Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'record-id',
                    'type' => 'A',
                    'name' => 'shop.example.test',
                    'content' => TestNetwork::public3(10),
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

    private function makeOtherProject(string $domain): void
    {
        $this->makeMainDomain($this->makeUser('bob', [], $domain), $domain);
    }

    private function aliasShopAs(string $alias): void
    {
        $shop = Domain::findByName('shop.example.test');
        $this->assertNotNull($shop);
        $shop->addAlias($alias);
        $shop->save();
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
        return self::failure(403, 'Unauthorized to access requested resource');
    }

    private static function failure(int $status, string $message): PromiseInterface
    {
        return Http::response([
            'success' => false,
            'errors' => [['code' => 9109, 'message' => $message]],
        ], $status);
    }
}
